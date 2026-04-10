<?php

namespace App\Http\Controllers\Employee;

use App\Http\Controllers\Controller;
use App\Models\Eta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use App\Models\Department;
use App\Models\User;
use setasign\Fpdi\Fpdi;
use Illuminate\Support\Facades\Mail;
use App\Mail\EtaNotification;

class EtaController extends Controller
{
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = Eta::where('user_id', $user->id)->orderBy('departure_date', 'desc');

        $filter = $request->query('filter');
        if ($filter === 'weekly') {
            $query->whereBetween('departure_date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()]);
        } elseif ($filter === 'monthly') {
            $query->whereMonth('departure_date', now()->month)->whereYear('departure_date', now()->year);
        }

        $etas = $query->paginate(10);

        return view('employee.ETA', compact('etas'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'departure_date' => 'required|date|after_or_equal:today',
            'arrival_date' => 'nullable|date|after_or_equal:departure_date',
            'destination' => 'required|string|max:255',
            'purpose' => ['required', 'string', 'in:Audit-Inspection-Licensing,Client Support,Conference,Construction Repair Maintenance,Economic Development,Legal-Law Enforcement,Legislator,Meeting,Training,Seminar,General Expense/Other'],
            'purpose_details' => 'nullable|string|max:1000',
        ]);

        $data['user_id'] = Auth::id();
        $eta = Eta::create($data + ['status' => 'pending']);

        // Determine department head and send notification
        $employee = User::find($eta->user_id);
        $departmentName = null;
        $departmentHead = null;
        if ($employee && !empty($employee->Dept_id)) {
            $department = Department::find($employee->Dept_id);
            if ($department) {
                $departmentName = $department->Dept_name ?? null;
                if (!empty($department->EmpNo) && $department->EmpNo !== 'UNASSIGNED') {
                    $departmentHead = User::where('EmpNo', $department->EmpNo)->first();
                }
            }
        }

        // attach department name for email template
        if ($employee) {
            $employee->department_name = $departmentName;
            if ($departmentHead) {
                $parts = [];
                if (!empty($departmentHead->first_name)) $parts[] = $departmentHead->first_name;
                if (!empty($departmentHead->middle_name)) $parts[] = $departmentHead->middle_name;
                if (!empty($departmentHead->last_name)) $parts[] = $departmentHead->last_name;
                if (empty($parts) && !empty($departmentHead->name)) $parts[] = $departmentHead->name;
                $employee->dept_head_name = implode(' ', $parts);
            }
        }

        if ($departmentHead && !empty($departmentHead->email)) {
            try {
                Mail::to($departmentHead->email)
                    ->cc($employee->email ?? null)
                    ->queue(new EtaNotification($employee, $eta));
            } catch (\Exception $ex) {
                // do not block on mail failure; consider logging
            }
        }

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'ETA filed successfully.',
                'redirect' => route('dashboard.employee.eta')
            ]);
        }

        return redirect()->route('dashboard.employee.eta')->with('success', 'ETA filed successfully.');
    }

    public function show(Eta $eta)
    {
        $this->authorize('view', $eta);

        $deptHeadName = null;
        $owner = User::find($eta->user_id);
        if ($owner && !empty($owner->Dept_id)) {
            $department = Department::find($owner->Dept_id);
            if ($department && !empty($department->EmpNo) && $department->EmpNo !== 'UNASSIGNED') {
                $head = User::where('EmpNo', $department->EmpNo)->first();
                if ($head) {
                    $parts = [];
                    if (!empty($head->first_name)) $parts[] = $head->first_name;
                    if (!empty($head->middle_name)) $parts[] = $head->middle_name;
                    if (!empty($head->last_name)) $parts[] = $head->last_name;
                    if (empty($parts) && !empty($head->name)) $parts[] = $head->name;
                    $deptHeadName = implode(' ', $parts);
                }
            }
        }

        return view('employee.eta-show', compact('eta', 'deptHeadName'));
    }

    public function print(Request $request)
    {
        $user = Auth::user();
        $filter = $request->query('filter');
        $query = Eta::where('user_id', $user->id)->orderBy('departure_date', 'desc');
        if ($filter === 'weekly') {
            $query->whereBetween('departure_date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()]);
        } elseif ($filter === 'monthly') {
            $query->whereMonth('departure_date', now()->month)->whereYear('departure_date', now()->year);
        }

        $etas = $query->get();

        $deptHeadName = null;
        if ($user && !empty($user->Dept_id)) {
            $department = Department::find($user->Dept_id);
            if ($department && !empty($department->EmpNo) && $department->EmpNo !== 'UNASSIGNED') {
                $head = User::where('EmpNo', $department->EmpNo)->first();
                if ($head) {
                    $parts = [];
                    if (!empty($head->first_name)) $parts[] = $head->first_name;
                    if (!empty($head->middle_name)) $parts[] = $head->middle_name;
                    if (!empty($head->last_name)) $parts[] = $head->last_name;
                    if (empty($parts) && !empty($head->name)) $parts[] = $head->name;
                    $deptHeadName = implode(' ', $parts);
                }
            }
        }

        return view('employee.eta-print', compact('etas', 'filter', 'deptHeadName'));
    }

    public function printSingle(Eta $eta)
    {
        $user = Auth::user();

        $allowed = false;
        if ($eta->user_id === $user->id) {
            $allowed = true;
        } else {
            $owner = $eta->user;
            $deptHeadUser = null;
            if ($owner && !empty($owner->Dept_id)) {
                $department = Department::find($owner->Dept_id);
                if ($department && !empty($department->EmpNo) && $department->EmpNo !== 'UNASSIGNED') {
                    $deptHeadUser = User::where('EmpNo', $department->EmpNo)->first();
                }
            } elseif ($owner && !empty($owner->EmpNo)) {
                $department = Department::where('EmpNo', $owner->EmpNo)->first();
                if ($department && !empty($department->EmpNo) && $department->EmpNo !== 'UNASSIGNED') {
                    $deptHeadUser = User::where('EmpNo', $department->EmpNo)->first();
                }
            }

            if ($deptHeadUser && $deptHeadUser->id === $user->id) {
                $allowed = true;
            }

            $role = strtolower(trim((string)$user->access_level));
            if ($role === 'administrative officer') {
                $allowed = true;
            }
        }

        if (! $allowed) {
            abort(403);
        }

        if ($eta->status !== 'approved') {
            abort(403);
        }

        // Use the ETA owner (not the authenticated user) for printed applicant details
        $owner = $eta->user ?? User::find($eta->user_id);

        $fullNameParts = [];
        if ($owner) {
            if (!empty($owner->first_name)) $fullNameParts[] = $owner->first_name;
            if (!empty($owner->middle_name)) $fullNameParts[] = $owner->middle_name;
            if (!empty($owner->last_name)) $fullNameParts[] = $owner->last_name;
            if (empty($fullNameParts) && !empty($owner->name)) $fullNameParts[] = $owner->name;
        }
        $fullName = implode(' ', $fullNameParts);

        $departure = $eta->departure_date ? (\Illuminate\Support\Carbon::parse($eta->departure_date)->toFormattedDateString()) : '';
        $arrival = $eta->arrival_date ? (\Illuminate\Support\Carbon::parse($eta->arrival_date)->toFormattedDateString()) : '';

        $dept = '';
        if ($owner && !empty($owner->Dept_id)) {
            $department = Department::find($owner->Dept_id);
            $dept = $department ? ($department->Dept_name ?? '') : '';
        }
        $position = $owner->designation ?? $owner->AcctName ?? '';
        $destination = $eta->destination ?? '';
        $dateapproved = $eta->updated_at ? $eta->updated_at->toDateString() : now()->toDateString();
        $purpose = $eta->purpose ?? '';
        $reason = $eta->purpose_details ?? $eta->purpose ?? '';

        $templatePath = storage_path('app/templates/ETA.pdf');
        if (!file_exists($templatePath)) {
            $etas = collect([$eta]);
            return view('employee.eta-print', compact('etas'))->with('filter', 'single');
        }

        $pdf = new Fpdi();
        $pdf->setSourceFile($templatePath);
        $tplId = $pdf->importPage(1);
        $pdf->AddPage();
        $pdf->useTemplate($tplId);

        // Fill fields
        $pdf->SetFont('Arial', '', 9);
        $pdf->SetXY(42, 43); $pdf->Write(5, $fullName);
        $pdf->SetXY(127, 49); $pdf->Write(5, $departure);
        $pdf->SetXY(127, 59); $pdf->Write(5, $arrival);
        $pdf->SetXY(42, 49); $pdf->Write(5, $dept);
        $pdf->SetXY(42, 54); $pdf->Write(5, $position);
        $pdf->SetXY(42, 59); $pdf->Write(5, $destination);
        $pdf->SetFont('Arial','B',9);
        $pdf->SetXY(130, 147); $pdf->Write(5, $dateapproved);

        // Purpose checkboxes
        $pdf->SetFont('Arial', 'B', 14);
        $etaPurposes = [
            'Audit-Inspection-Licensing' => [62, 64],
            'Client Support'              => [111, 64],
            'Conference'                  => [145, 64],
            'Construction Repair Maintenance' => [14, 69],
            'Economic Development'        => [78, 69],
            'Legal-Law Enforcement'       => [14, 74],
            'Legislator'                  => [61, 74],
            'Meeting'                     => [95, 74],
            'Training'                    => [128, 74],
            'Seminar'                     => [161, 74],
            'General Expense/Other'       => [125, 69]
        ];

        if (isset($etaPurposes[$purpose])) {
            [$x, $y] = $etaPurposes[$purpose];
            $pdf->SetXY($x, $y);
            $pdf->Write(5,'X');
        }
        // Department head
        $deptHeadName = null;
        if (!empty($user->Dept_id)) {
            $department = Department::find($user->Dept_id);
            if ($department && !empty($department->EmpNo) && $department->EmpNo !== 'UNASSIGNED') {
                $head = User::where('EmpNo', $department->EmpNo)->first();
                if ($head) {
                    $parts = [];
                    if (!empty($head->first_name)) $parts[] = $head->first_name;
                    if (!empty($head->middle_name)) $parts[] = $head->middle_name;
                    if (!empty($head->last_name)) $parts[] = $head->last_name;
                    if (empty($parts) && !empty($head->name)) $parts[] = $head->name;
                    $deptHeadName = implode(' ', $parts);
                }
            }
        }
        if ($deptHeadName) {
            $pdf->SetFont('Arial','B',9);
            $pdf->SetXY(20, 148); $pdf->Write(5, $deptHeadName);
        }
        $pdf->SetFont('Arial','B',11);
        $pdf->SetXY(40, 131); $pdf->Write(11,'X');
        $pdf->SetFont('Arial','',11);
        $pdf->SetXY(23,84);
        $pdf->MultiCell(115,5,$reason);

        $pdfContent = $pdf->Output('S');
        return response($pdfContent, 200)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'inline; filename="eta-'. $eta->id .'.pdf"');
    }

    public function data(Request $request)
    {
        $user = Auth::user();
        $query = Eta::where('user_id', $user->id)->orderBy('departure_date', 'desc');

        $filter = $request->query('filter');
        if ($filter === 'weekly') {
            $query->whereBetween('departure_date', [now()->startOfWeek()->toDateString(), now()->endOfWeek()->toDateString()]);
        } elseif ($filter === 'monthly') {
            $query->whereMonth('departure_date', now()->month)->whereYear('departure_date', now()->year);
        }

        // resolve department head once for this user
        $deptHeadName = null;
        if ($user && !empty($user->Dept_id)) {
            $department = Department::find($user->Dept_id);
            if ($department && !empty($department->EmpNo) && $department->EmpNo !== 'UNASSIGNED') {
                $head = User::where('EmpNo', $department->EmpNo)->first();
                if ($head) {
                    $parts = [];
                    if (!empty($head->first_name)) $parts[] = $head->first_name;
                    if (!empty($head->middle_name)) $parts[] = $head->middle_name;
                    if (!empty($head->last_name)) $parts[] = $head->last_name;
                    if (empty($parts) && !empty($head->name)) $parts[] = $head->name;
                    $deptHeadName = implode(' ', $parts);
                }
            }
        }

        $etas = $query->get()->map(function ($eta) use ($deptHeadName) {
            return [
                'id' => $eta->id,
                'departure_date' => $eta->departure_date,
                'arrival_date' => $eta->arrival_date,
                'destination' => $eta->destination,
                'purpose' => $eta->purpose,
                'purpose_details' => $eta->purpose_details ?? null,
                'dept_head' => $deptHeadName,
                'status' => $eta->status,
                'created_at' => $eta->created_at->toDateTimeString(),
                'can_print' => $eta->status === 'approved',
                'print_url' => route('employee.eta.print.single', ['eta' => $eta->id]),
            ];
        });

        return response()->json(['data' => $etas]);
    }

//    This is cancel
    public function cancel(Request $request, Eta $eta)
    {
        $user = Auth::user();
        if ($eta->user_id !== $user->id) {
            abort(403);
        }

        if ($eta->status !== 'pending') {
            if ($request->wantsJson() || $request->ajax()) {
                return response()->json(['success' => false, 'message' => 'Only pending ETAs can be cancelled.'], 400);
            }
            return redirect()->back()->with('error', 'Only pending ETAs can be cancelled.');
        }

        $eta->status = 'cancelled';
        $eta->save();

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json(['success' => true, 'message' => 'ETA cancelled.']);
        }

        return redirect()->route('dashboard.employee.eta')->with('success', 'ETA cancelled.');
    }
}

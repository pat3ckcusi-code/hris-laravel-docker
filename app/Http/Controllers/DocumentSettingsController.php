<?php

namespace App\Http\Controllers;

use App\Models\DocumentType;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;

class DocumentSettingsController extends Controller
{
    public function index(): View
    {
        $this->ensureFrontDesk();
        $documentTypes = DocumentType::all();

        return view('employee.document-settings', [
            'documentTypes' => $documentTypes,
        ]);
    }

    public function create(): View
    {
        $this->ensureFrontDesk();

        return view('employee.document-settings-create');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureFrontDesk();

        $request->validate([
            'name'                      => ['required', 'string', 'max:255', 'unique:document_types,name'],
            'title'                     => ['nullable', 'string', 'max:255'],
            'salutation'                => ['nullable', 'string', 'max:255'],
            'header_image'              => ['nullable', 'image', 'max:4096'],
            'body_text'                 => ['nullable', 'string', 'max:10000'],
            'body_font'                 => ['nullable', 'string', 'max:100'],
            'body_size'                 => ['nullable', 'integer', 'min:6', 'max:72'],
            'body_color'                => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'closing_remark_text'       => ['nullable', 'string', 'max:10000'],
            'closing_remark_font'       => ['nullable', 'string', 'max:100'],
            'closing_remark_size'       => ['nullable', 'integer', 'min:6', 'max:72'],
            'closing_remark_color'      => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'signatories'               => ['nullable', 'array', 'max:20'],
            'signatories.*.name'        => ['nullable', 'string', 'max:255'],
            'signatories.*.designation' => ['nullable', 'string', 'max:255'],
            'signatories.*.name_font'   => ['nullable', 'string', 'max:100'],
            'signatories.*.name_size'   => ['nullable', 'integer', 'min:6', 'max:72'],
            'signatories.*.name_color'  => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'signatories.*.desig_font'  => ['nullable', 'string', 'max:100'],
            'signatories.*.desig_size'  => ['nullable', 'integer', 'min:6', 'max:72'],
            'signatories.*.desig_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'footer_text'               => ['nullable', 'string', 'max:10000'],
            'footer_font'               => ['nullable', 'string', 'max:100'],
            'footer_size'               => ['nullable', 'integer', 'min:6', 'max:72'],
            'footer_color'              => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'footer_image'              => ['nullable', 'image', 'max:4096'],
        ]);

        $parts = $this->buildPartsFromRequest($request, null);

        DocumentType::create([
            'name'           => $request->input('name'),
            'parts'          => $parts,
            'header_image'   => $parts['header_image'] ?? null,
            'footer_image'   => $parts['footer']['image'] ?? null,
        ]);

        return redirect()->route('employee.document-settings')
            ->with('success', 'Document type created successfully.');
    }

    public function edit(DocumentType $documentType): View
    {
        $this->ensureFrontDesk();

        return view('employee.document-settings-edit', [
            'documentType' => $documentType,
        ]);
    }

    public function update(Request $request, DocumentType $documentType): RedirectResponse
    {
        $this->ensureFrontDesk();

        $request->validate([
            'name'                      => ['required', 'string', 'max:255', 'unique:document_types,name,' . $documentType->id],
            'title'                     => ['nullable', 'string', 'max:255'],
            'salutation'                => ['nullable', 'string', 'max:255'],
            'header_image'              => ['nullable', 'image', 'max:4096'],
            'body_text'                 => ['nullable', 'string', 'max:10000'],
            'body_font'                 => ['nullable', 'string', 'max:100'],
            'body_size'                 => ['nullable', 'integer', 'min:6', 'max:72'],
            'body_color'                => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'closing_remark_text'       => ['nullable', 'string', 'max:10000'],
            'closing_remark_font'       => ['nullable', 'string', 'max:100'],
            'closing_remark_size'       => ['nullable', 'integer', 'min:6', 'max:72'],
            'closing_remark_color'      => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'signatories'               => ['nullable', 'array', 'max:20'],
            'signatories.*.name'        => ['nullable', 'string', 'max:255'],
            'signatories.*.designation' => ['nullable', 'string', 'max:255'],
            'signatories.*.name_font'   => ['nullable', 'string', 'max:100'],
            'signatories.*.name_size'   => ['nullable', 'integer', 'min:6', 'max:72'],
            'signatories.*.name_color'  => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'signatories.*.desig_font'  => ['nullable', 'string', 'max:100'],
            'signatories.*.desig_size'  => ['nullable', 'integer', 'min:6', 'max:72'],
            'signatories.*.desig_color' => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'footer_text'               => ['nullable', 'string', 'max:10000'],
            'footer_font'               => ['nullable', 'string', 'max:100'],
            'footer_size'               => ['nullable', 'integer', 'min:6', 'max:72'],
            'footer_color'              => ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'footer_image'              => ['nullable', 'image', 'max:4096'],
        ]);

        $headerPath = $documentType->header_image ?: (($documentType->parts['header_image'] ?? null));
        if ($request->hasFile('header_image')) {
            if (!empty($headerPath)) {
                Storage::disk('public')->delete((string) $headerPath);
            }
            $headerPath = $request->file('header_image')->store('document-types', 'public');
            $documentType->header_image = $headerPath;
        }

        $footerPath = $documentType->footer_image ?: (($documentType->parts['footer']['image'] ?? null));
        if ($request->hasFile('footer_image')) {
            if (!empty($footerPath)) {
                Storage::disk('public')->delete((string) $footerPath);
            }
            $footerPath = $request->file('footer_image')->store('document-types', 'public');
            $documentType->footer_image = $footerPath;
        }

        $documentType->save();

        $parts = $this->buildPartsFromRequest($request, $documentType, $headerPath, $footerPath);
        
        $documentType->update([
            'name'           => $request->input('name'),
            'parts'          => $parts,
            'header_image'   => $parts['header_image'] ?? null,
            'footer_image'   => $parts['footer']['image'] ?? null,
        ]);

        return redirect()->route('employee.document-settings')
            ->with('success', 'Document type updated successfully.');
    }

    public function destroy(DocumentType $documentType): RedirectResponse
    {
        $this->ensureFrontDesk();

        $parts = $documentType->parts ?? [];
        if (!empty($parts['header_image'])) {
            Storage::disk('public')->delete($parts['header_image']);
        }
        $footer = $parts['footer'] ?? [];
        if (is_array($footer) && !empty($footer['image'])) {
            Storage::disk('public')->delete($footer['image']);
        }

        $documentType->delete();

        return redirect()->route('employee.document-settings')
            ->with('success', 'Document type deleted successfully.');
    }

    private function buildPartsFromRequest(
        Request $request,
        ?DocumentType $existing,
        ?string $headerImageOverride = null,
        ?string $footerImageOverride = null
    ): array
    {
        $existingParts = $existing?->parts ?? [];

        $headerImage = $headerImageOverride ?? ($existingParts['header_image'] ?? null);
        if ($headerImageOverride === null && $request->hasFile('header_image')) {
            if (!empty($headerImage)) {
                Storage::disk('public')->delete((string) $headerImage);
            }
            $headerImage = $request->file('header_image')->store('document-types', 'public');
        }

        $existingFooter = is_array($existingParts['footer'] ?? null) ? ($existingParts['footer'] ?? []) : [];
        $footerImage = $footerImageOverride ?? ($existingFooter['image'] ?? null);
        if ($footerImageOverride === null && $request->hasFile('footer_image')) {
            if (!empty($footerImage)) {
                Storage::disk('public')->delete((string) $footerImage);
            }
            $footerImage = $request->file('footer_image')->store('document-types', 'public');
        }

        $signatories = [];
        foreach ((array) $request->input('signatories', []) as $sig) {
            if (empty(trim($sig['name'] ?? '')) && empty(trim($sig['designation'] ?? ''))) {
                continue;
            }
            $signatories[] = [
                'name'        => trim($sig['name'] ?? ''),
                'designation' => trim($sig['designation'] ?? ''),
                'name_font'   => $sig['name_font'] ?? 'Times New Roman',
                'name_size'   => (int) ($sig['name_size'] ?? 14),
                'name_color'  => $sig['name_color'] ?? '#000000',
                'name_bold'   => !empty($sig['name_bold']),
                'name_italic' => !empty($sig['name_italic']),
                'desig_font'  => $sig['desig_font'] ?? 'Times New Roman',
                'desig_size'  => (int) ($sig['desig_size'] ?? 11),
                'desig_color' => $sig['desig_color'] ?? '#000000',
                'desig_italic' => !empty($sig['desig_italic']),
            ];
        }

        return [
            'title'      => $request->input('title', ''),
            'salutation' => $request->input('salutation', ''),
            'header_image' => $headerImage,
            'body' => [
                'text'      => $request->input('body_text', ''),
                'font'      => $request->input('body_font', 'Arial'),
                'size'      => (int) $request->input('body_size', 12),
                'color'     => $request->input('body_color', '#000000'),
                'bold'      => (bool) $request->input('body_bold'),
                'italic'    => (bool) $request->input('body_italic'),
                'underline' => (bool) $request->input('body_underline'),
            ],
            'closing_remark' => [
                'text'      => $request->input('closing_remark_text', ''),
                'font'      => $request->input('closing_remark_font', 'Arial'),
                'size'      => (int) $request->input('closing_remark_size', 12),
                'color'     => $request->input('closing_remark_color', '#000000'),
                'bold'      => (bool) $request->input('closing_remark_bold'),
                'italic'    => (bool) $request->input('closing_remark_italic'),
                'underline' => (bool) $request->input('closing_remark_underline'),
            ],
            'signatories' => $signatories,
            'footer' => [
                'text'      => $request->input('footer_text', ''),
                'font'      => $request->input('footer_font', 'Calibri'),
                'size'      => (int) $request->input('footer_size', 10),
                'color'     => $request->input('footer_color', '#000000'),
                'italic'    => (bool) $request->input('footer_italic'),
                'underline' => (bool) $request->input('footer_underline'),
                'image'     => $footerImage,
            ],
        ];
    }

    private function ensureFrontDesk(): void
    {
        $role = strtolower(trim(str_replace(['_', '-'], ' ', (string) (auth()->user()->access_level ?? ''))));
        abort_unless($role === 'front desk', 403, 'Only Front Desk users can manage document settings.');
    }
}

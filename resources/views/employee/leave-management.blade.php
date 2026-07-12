@extends('dashboards.layout')

@php
    $title = 'Leave Management';
    $subtitle = 'Manage all types of leave requests and approvals.';
@endphp

@section('page_styles')
    @vite(['resources/css/hris-table.css', 'resources/js/hris-table.js'])
@endsection

@section('content')
    <div class="space-y-6">

        {{-- Notifications --}}
        @if(session('success'))
            <div class="rounded-lg bg-green-50 p-4 text-sm text-green-800 border border-green-200">{{ session('success') }}</div>
        @endif
        @if($errors->any())
            <div class="rounded-lg bg-red-50 p-4 text-sm text-red-800 border border-red-200">
                <ul class="list-disc list-inside space-y-1">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- Leave Balances Section --}}
        <div class="bg-white p-6 rounded-xl shadow-md">
            <h2 class="text-xl font-bold text-slate-900 mb-5">Leave Balances</h2>
            <div class="flex gap-3">

                {{-- Vacation Leave --}}
                <div class="relative flex-1 min-w-0 bg-gradient-to-br from-blue-50 to-white border border-blue-100 rounded-xl p-4 shadow-sm hover:shadow-md transition-shadow">
                    <div class="absolute top-2.5 right-2.5 group/vl">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-slate-400 cursor-help" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                        <div class="pointer-events-none invisible group-hover/vl:visible opacity-0 group-hover/vl:opacity-100 transition-opacity duration-150 absolute bottom-full right-0 mb-2 w-56 bg-slate-800 text-white text-[11px] leading-relaxed rounded-lg px-3 py-2 z-50 shadow-xl">
                            Accrued at 1.25 days/month (15 days/year). File at least 5 calendar days in advance. For rest, recreation, or personal travel.
                            <span class="absolute top-full right-3 border-l-4 border-r-4 border-t-4 border-l-transparent border-r-transparent border-t-slate-800"></span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2.5 mb-3">
                        <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-blue-100 flex items-center justify-center text-xl">🌴</div>
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold text-blue-600 uppercase tracking-wider leading-none">VL</p>
                            <p class="text-[11px] font-medium text-slate-500 leading-tight">Vacation Leave</p>
                        </div>
                    </div>
                    <p class="text-2xl font-extrabold text-slate-900 tabular-nums">{{ preg_replace('/\.(\d{3})\d*/', '.$1', sprintf('%.10f', optional($user->leaveBalance)->VL ?? 0)) }}</p>
                </div>

                {{-- Sick Leave --}}
                <div class="relative flex-1 min-w-0 bg-gradient-to-br from-rose-50 to-white border border-rose-100 rounded-xl p-4 shadow-sm hover:shadow-md transition-shadow">
                    <div class="absolute top-2.5 right-2.5 group/sl">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-slate-400 cursor-help" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                        <div class="pointer-events-none invisible group-hover/sl:visible opacity-0 group-hover/sl:opacity-100 transition-opacity duration-150 absolute bottom-full right-0 mb-2 w-56 bg-slate-800 text-white text-[11px] leading-relaxed rounded-lg px-3 py-2 z-50 shadow-xl">
                            Accrued at 1.25 days/month (15 days/year). Used for personal illness, medical consultations, or caring for sick family members.
                            <span class="absolute top-full right-3 border-l-4 border-r-4 border-t-4 border-l-transparent border-r-transparent border-t-slate-800"></span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2.5 mb-3">
                        <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-rose-100 flex items-center justify-center text-xl">🏥</div>
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold text-rose-600 uppercase tracking-wider leading-none">SL</p>
                            <p class="text-[11px] font-medium text-slate-500 leading-tight">Sick Leave</p>
                        </div>
                    </div>
                    <p class="text-2xl font-extrabold text-slate-900 tabular-nums">{{ preg_replace('/\.(\d{3})\d*/', '.$1', sprintf('%.10f', optional($user->leaveBalance)->SL ?? 0)) }}</p>
                    <p class="text-[10px] font-medium text-slate-400 mt-1 uppercase tracking-wide"></p>
                </div>

                {{-- Wellness Leave --}}
                <div class="relative flex-1 min-w-0 bg-gradient-to-br from-emerald-50 to-white border border-emerald-100 rounded-xl p-4 shadow-sm hover:shadow-md transition-shadow">
                    <div class="absolute top-2.5 right-2.5 group/wlns">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-slate-400 cursor-help" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                        <div class="pointer-events-none invisible group-hover/wlns:visible opacity-0 group-hover/wlns:opacity-100 transition-opacity duration-150 absolute bottom-full right-0 mb-2 w-56 bg-slate-800 text-white text-[11px] leading-relaxed rounded-lg px-3 py-2 z-50 shadow-xl">
                            Wellness Leave credit per LGU policy. Granted for health and wellness activities, mental health rest days, and preventive care.
                            <span class="absolute top-full right-3 border-l-4 border-r-4 border-t-4 border-l-transparent border-r-transparent border-t-slate-800"></span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2.5 mb-3">
                        <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-emerald-100 flex items-center justify-center text-xl">💆</div>
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold text-emerald-600 uppercase tracking-wider leading-none">WLNS</p>
                            <p class="text-[11px] font-medium text-slate-500 leading-tight">Wellness Leave</p>
                        </div>
                    </div>
                    <p class="text-2xl font-extrabold text-slate-900 tabular-nums">{{ preg_replace('/\.(\d{3})\d*/', '.$1', sprintf('%.10f', optional($user->leaveBalance)->WLNS ?? 0)) }}</p>
                    <p class="text-[10px] font-medium text-slate-400 mt-1 uppercase tracking-wide"></p>
                </div>

                {{-- Compensatory Time Off --}}
                <div class="relative flex-1 min-w-0 bg-gradient-to-br from-amber-50 to-white border border-amber-100 rounded-xl p-4 shadow-sm hover:shadow-md transition-shadow">
                    <div class="absolute top-2.5 right-2.5 group/cto">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-slate-400 cursor-help" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                        <div class="pointer-events-none invisible group-hover/cto:visible opacity-0 group-hover/cto:opacity-100 transition-opacity duration-150 absolute bottom-full right-0 mb-2 w-56 bg-slate-800 text-white text-[11px] leading-relaxed rounded-lg px-3 py-2 z-50 shadow-xl">
                            Earned for work rendered on special non-working holidays or approved overtime. Subject to supervisor approval and scheduling.
                            <span class="absolute top-full right-3 border-l-4 border-r-4 border-t-4 border-l-transparent border-r-transparent border-t-slate-800"></span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2.5 mb-3">
                        <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-amber-100 flex items-center justify-center text-xl">⏱️</div>
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold text-amber-600 uppercase tracking-wider leading-none">CTO</p>
                            <p class="text-[11px] font-medium text-slate-500 leading-tight">Comp. Time Off</p>
                        </div>
                    </div>
                    <p class="text-2xl font-extrabold text-slate-900 tabular-nums">{{ preg_replace('/\.(\d{3})\d*/', '.$1', sprintf('%.10f', optional($user->leaveBalance)->CTO ?? 0)) }}</p>
                </div>

                {{-- Special Privilege Leave --}}
                <div class="relative flex-1 min-w-0 bg-gradient-to-br from-violet-50 to-white border border-violet-100 rounded-xl p-4 shadow-sm hover:shadow-md transition-shadow">
                    <div class="absolute top-2.5 right-2.5 group/spl">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-slate-400 cursor-help" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                        <div class="pointer-events-none invisible group-hover/spl:visible opacity-0 group-hover/spl:opacity-100 transition-opacity duration-150 absolute bottom-full right-0 mb-2 w-56 bg-slate-800 text-white text-[11px] leading-relaxed rounded-lg px-3 py-2 z-50 shadow-xl">
                            3 days per year for personal milestones: graduation, wedding anniversary, or birthdays of immediate family members. Non-cumulative.
                            <span class="absolute top-full right-3 border-l-4 border-r-4 border-t-4 border-l-transparent border-r-transparent border-t-slate-800"></span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2.5 mb-3">
                        <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-violet-100 flex items-center justify-center text-xl">⭐</div>
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold text-violet-600 uppercase tracking-wider leading-none">SPL</p>
                            <p class="text-[11px] font-medium text-slate-500 leading-tight">Special Privilege</p>
                        </div>
                    </div>
                    <p class="text-2xl font-extrabold text-slate-900 tabular-nums">{{ preg_replace('/\.(\d{3})\d*/', '.$1', sprintf('%.10f', optional($user->leaveBalance)->SPL ?? 0)) }}</p>
                </div>

                {{-- Solo Parent Leave --}}
                <div class="relative flex-1 min-w-0 bg-gradient-to-br from-indigo-50 to-white border border-indigo-100 rounded-xl p-4 shadow-sm hover:shadow-md transition-shadow">
                    <div class="absolute top-2.5 right-2.5 group/sp">
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 text-slate-400 cursor-help" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                        <div class="pointer-events-none invisible group-hover/sp:visible opacity-0 group-hover/sp:opacity-100 transition-opacity duration-150 absolute bottom-full right-0 mb-2 w-56 bg-slate-800 text-white text-[11px] leading-relaxed rounded-lg px-3 py-2 z-50 shadow-xl">
                            7 working days per year under RA 8972 (Solo Parents' Welfare Act). Requires a valid Solo Parent ID from the DSWD.
                            <span class="absolute top-full right-3 border-l-4 border-r-4 border-t-4 border-l-transparent border-r-transparent border-t-slate-800"></span>
                        </div>
                    </div>
                    <div class="flex items-center gap-2.5 mb-3">
                        <div class="flex-shrink-0 w-9 h-9 rounded-lg bg-indigo-100 flex items-center justify-center text-xl">👩‍👧</div>
                        <div class="min-w-0">
                            <p class="text-[10px] font-bold text-indigo-600 uppercase tracking-wider leading-none">SP</p>
                            <p class="text-[11px] font-medium text-slate-500 leading-tight">Solo Parent</p>
                        </div>
                    </div>
                    <p class="text-2xl font-extrabold text-slate-900 tabular-nums">{{ preg_replace('/\.(\d{3})\d*/', '.$1', sprintf('%.10f', optional($user->leaveBalance)->SP ?? 0)) }}</p>
                </div>

            </div>
        </div>
                    <script>
                        document.addEventListener('DOMContentLoaded', function () {
                            const cancelForms = document.querySelectorAll('form.cancel-leave-form');
                            cancelForms.forEach(form => {
                                form.addEventListener('submit', function (e) {
                                    e.preventDefault();
                                    if (window.Swal) {
                                        window.Swal.fire({
                                            title: 'Cancel leave request?',
                                            text: 'Are you sure you want to cancel this leave request?',
                                            icon: 'warning',
                                            showCancelButton: true,
                                            confirmButtonText: 'Yes, cancel',
                                            cancelButtonText: 'Keep'
                                        }).then(result => {
                                            if (result.isConfirmed) form.submit();
                                        });
                                    } else {
                                        if (confirm('Cancel this leave request?')) form.submit();
                                    }
                                });
                            });
                        });
                    </script>

        {{-- Leave Application Form --}}
        <div class="bg-white p-6 rounded-xl shadow-md">
            <h2 class="text-xl font-bold text-slate-900 mb-4">Apply for Leave</h2>
            <form method="POST" action="{{ route('employee.leave.apply') }}" class="space-y-6">
                @csrf
                <div class="space-y-3 bg-blue-50 border border-blue-200 rounded-lg p-4">
                    <p class="text-sm text-blue-700 font-medium">Select up to 3 leave types in one application. The following must be filed as a separate application: Maternity, Paternity, Adoption, VAWC, Special Leave (Gynecological), Rehabilitation Privilege, Study / Examination Leave, and Mandatory/Forced Leave.</p>
                    <div id="exclusiveNotice" style="display:none;" class="text-sm font-semibold text-amber-700 bg-amber-50 border border-amber-300 rounded px-3 py-2"></div>
                    @php $isMandatoryMonth = in_array(now()->month, [11, 12]); @endphp
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
                        <label class="flex items-center space-x-2 cursor-pointer hover:bg-blue-100 p-2 rounded">
                            <input type="checkbox" name="leave_types[]" value="Vacation Leave" class="w-4 h-4 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                            <span class="text-sm text-slate-700">Vacation Leave (VL)</span>
                        </label>
                        <label class="flex items-center space-x-2 cursor-pointer hover:bg-blue-100 p-2 rounded">
                            <input type="checkbox" name="leave_types[]" value="Sick Leave" class="w-4 h-4 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                            <span class="text-sm text-slate-700">Sick Leave (SL)</span>
                        </label>
                        <label class="flex items-center space-x-2 cursor-pointer hover:bg-blue-100 p-2 rounded">
                            <input type="checkbox" name="leave_types[]" value="Maternity Leave" class="w-4 h-4 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                            <span class="text-sm text-slate-700">Maternity Leave</span>
                        </label>
                        <label class="flex items-center space-x-2 cursor-pointer hover:bg-blue-100 p-2 rounded">
                            <input type="checkbox" name="leave_types[]" value="Paternity Leave" class="w-4 h-4 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                            <span class="text-sm text-slate-700">Paternity Leave</span>
                        </label>
                        <label class="flex items-center space-x-2 cursor-pointer hover:bg-blue-100 p-2 rounded">
                            <input type="checkbox" name="leave_types[]" value="Adoption Leave" class="w-4 h-4 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                            <span class="text-sm text-slate-700">Adoption Leave</span>
                        </label>
                        <label class="flex items-center space-x-2 cursor-pointer hover:bg-blue-100 p-2 rounded">
                            <input type="checkbox" name="leave_types[]" value="Solo Parent Leave" class="w-4 h-4 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                            <span class="text-sm text-slate-700">Solo Parent Leave</span>
                        </label>
                        <label class="flex items-center space-x-2 cursor-pointer hover:bg-blue-100 p-2 rounded">
                            <input type="checkbox" name="leave_types[]" value="VAWC Leave" class="w-4 h-4 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                            <span class="text-sm text-slate-700">VAWC Leave</span>
                        </label>
                        <label class="flex items-center space-x-2 cursor-pointer hover:bg-blue-100 p-2 rounded">
                            <input type="checkbox" name="leave_types[]" value="Special Leave (Gynecological)" class="w-4 h-4 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                            <span class="text-sm text-slate-700">Special Leave (Gynecological)</span>
                        </label>
                        <label class="flex items-center space-x-2 cursor-pointer hover:bg-blue-100 p-2 rounded">
                            <input type="checkbox" name="leave_types[]" value="Special Emergency (Calamity) Leave" class="w-4 h-4 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                            <span class="text-sm text-slate-700">Special Emergency Leave</span>
                        </label>
                        <label class="flex items-center space-x-2 cursor-pointer hover:bg-blue-100 p-2 rounded">
                            <input type="checkbox" name="leave_types[]" value="Special Privilege Leave" class="w-4 h-4 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                            <span class="text-sm text-slate-700">Special Privilege Leave</span>
                        </label>
                        <label class="flex items-center space-x-2 p-2 rounded {{ $isMandatoryMonth ? 'cursor-pointer hover:bg-blue-100' : 'opacity-40 cursor-not-allowed' }}"
                               title="{{ $isMandatoryMonth ? '' : 'Only available in November and December' }}">
                            <input type="checkbox" name="leave_types[]" value="Mandatory/Forced Leave"
                                   class="w-4 h-4 text-blue-600 rounded focus:ring-2 focus:ring-blue-500"
                                   data-month-restricted="{{ $isMandatoryMonth ? 'false' : 'true' }}"
                                   {{ $isMandatoryMonth ? '' : 'disabled' }}>
                            <span class="text-sm text-slate-700">Mandatory/Forced Leave</span>
                        </label>
                        <label class="flex items-center space-x-2 cursor-pointer hover:bg-blue-100 p-2 rounded">
                            <input type="checkbox" name="leave_types[]" value="Rehabilitation Privilege" class="w-4 h-4 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                            <span class="text-sm text-slate-700">Rehabilitation Privilege</span>
                        </label>
                        <label class="flex items-center space-x-2 cursor-pointer hover:bg-blue-100 p-2 rounded">
                            <input type="checkbox" name="leave_types[]" value="Wellness Leave" class="w-4 h-4 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                            <span class="text-sm text-slate-700">Wellness Leave</span>
                        </label>
                        <label class="flex items-center space-x-2 cursor-pointer hover:bg-blue-100 p-2 rounded">
                            <input type="checkbox" name="leave_types[]" value="Study / Examination Leave" class="w-4 h-4 text-blue-600 rounded focus:ring-2 focus:ring-blue-500">
                            <span class="text-sm text-slate-700">Study / Examination Leave</span>
                        </label>
                    </div>
                </div>
                <div id="individualDateSection" class="border-t pt-6">
                    <h3 class="text-lg font-semibold text-slate-900 mb-4">Select Dates & Allocate Days</h3>
                    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                        <div class="lg:col-span-1 space-y-4">
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-slate-700">Select Dates</label>
                                <div class="flex gap-1">
                                    <input type="date" id="datePicker" class="flex-1 rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500" />
                                    <button type="button" id="addDateBtn" class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">Add</button>
                                </div>
                                <p class="text-xs text-slate-500">Select multiple non-consecutive weekdays. Weekends and holidays are excluded.</p>
                                <div id="datePickerMsg" class="text-sm text-red-600 font-medium mt-2 hidden"></div>
                                <input type="hidden" name="leave_dates" id="leaveDatesInput" />
                            </div>
                            <div class="space-y-2">
                                <label class="block text-sm font-medium text-slate-700">Selected Dates</label>
                                <div id="selectedDatesList" class="border border-gray-300 rounded-lg p-3 min-h-44 bg-gray-50 overflow-y-auto"></div>
                            </div>
                        </div>
                        <div class="lg:col-span-3 space-y-2">
                            <label class="block text-sm font-medium text-slate-700">Day Allocation Per Leave Type</label>
                            <div id="allocationSection" class="border border-gray-300 rounded-lg p-3 min-h-96 bg-gray-50 overflow-y-auto"></div>
                            <p class="text-xs text-slate-500">Assign each date to a leave type. Totals auto-compute to avoid errors.</p>
                        </div>
                    </div>
                </div>

                {{-- Range-based date section for extended leave types --}}
                <div id="rangeSection" style="display:none;" class="border-t pt-6">
                    <h3 class="text-lg font-semibold text-slate-900 mb-4">Leave Duration</h3>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 max-w-lg">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Start Date</label>
                            <input type="date" id="rangeStart" name="range_start" class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">End Date</label>
                            <input type="date" id="rangeEnd" name="range_end" class="w-full rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500">
                        </div>
                    </div>
                    <p class="mt-3 text-sm text-slate-600">Estimated working days (Mon–Fri): <span id="rangeDaysCount" class="font-semibold text-blue-700">-</span></p>
                    <input type="hidden" name="extended_leave_mode" id="extendedLeaveModeInput" value="0">
                </div>

                <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const datePicker = document.getElementById('datePicker');
                    const addDateBtn = document.getElementById('addDateBtn');
                    const selectedDatesList = document.getElementById('selectedDatesList');
                    const leaveDatesInput = document.getElementById('leaveDatesInput');
                    let selectedDates = [];

                    function renderDates() {
                        selectedDatesList.innerHTML = '';
                        selectedDates.forEach((date, idx) => {
                            const row = document.createElement('div');
                            row.style.display = 'flex';
                            row.style.alignItems = 'center';
                            row.style.marginBottom = '6px';
                            const span = document.createElement('span');
                            span.textContent = date;
                            span.style.flex = '1';
                            const btn = document.createElement('button');
                            btn.type = 'button';
                            btn.textContent = '×';
                            btn.className = 'btn';
                            btn.style.background = '#ef4444';
                            btn.style.color = '#fff';
                            btn.style.fontWeight = 'bold';
                            btn.style.fontSize = '1em';
                            btn.style.marginLeft = '8px';
                            btn.style.padding = '2px 10px';
                            btn.onclick = function () {
                                selectedDates.splice(idx, 1);
                                renderDates();
                            };
                            row.appendChild(span);
                            row.appendChild(btn);
                            selectedDatesList.appendChild(row);
                        });
                        leaveDatesInput.value = selectedDates.join(',');
                    }

                    const datePickerMsg = document.getElementById('datePickerMsg');

                    window.getCheckedLeaveTypes = function () {
                        const boxes = document.querySelectorAll('input[name="leave_types[]"]');
                        return Array.from(boxes).filter(cb => cb.checked).map(cb => ({
                            value: cb.value,
                            label: cb.parentElement ? cb.parentElement.textContent.trim() : cb.value
                        }));
                    };

                    function updateDatePickerState() {
                        const val = datePicker.value;
                        if (!val) {
                            addDateBtn.disabled = false;
                            datePickerMsg.style.display = 'none';
                            return;
                        }
                        const d = new Date(val); d.setHours(0,0,0,0);
                        // weekend
                        // if (d.getDay() === 0 || d.getDay() === 6) {
                        //     addDateBtn.disabled = true;
                        //     datePickerMsg.style.display = '';
                        //     datePickerMsg.textContent = 'Weekends are excluded.';
                        //     return;
                        // }
                        addDateBtn.disabled = false;
                        datePickerMsg.style.display = 'none';
                    }

                    datePicker.addEventListener('change', updateDatePickerState);

                    addDateBtn.onclick = function () {
                        const val = datePicker.value;
                        if (!val) { 
                            if (window.Swal) { window.Swal.fire({ icon: 'warning', title: 'Missing date', text: 'Please select a date.' }); } else { alert('Please select a date.'); }
                            return; 
                        }
                        if (selectedDates.includes(val)) { 
                            if (window.Swal) { window.Swal.fire({ icon: 'warning', title: 'Duplicate date', text: 'Date already selected.' }); } else { alert('Date already selected.'); }
                            return; 
                        }
                        const d = new Date(val); d.setHours(0,0,0,0);
                        // Exclude weekends
                        // if (d.getDay() === 0 || d.getDay() === 6) { 
                        //     if (window.Swal) { window.Swal.fire({ icon: 'warning', title: 'Invalid date', text: 'Weekends are excluded.' }); } else { alert('Weekends are excluded.'); }
                        //     return; 
                        // }
                        // Per-date vacation lead-time will be validated on submit
                        selectedDates.push(val);
                        selectedDates.sort();
                        renderDates();
                        datePicker.value = '';
                        updateDatePickerState();
                    };
                    renderDates();

                    // Client-side validation before submit for Vacation Leave
                    const form = leaveDatesInput.closest('form');
                    if (form) {
                        form.addEventListener('submit', function (e) {
                            e.preventDefault();
                            const checked = getCheckedLeaveTypes().map(t => t.value);
                            const raw = (leaveDatesInput && leaveDatesInput.value) ? leaveDatesInput.value : '';
                            const sel = raw ? raw.split(',').filter(s => s) : [];

                            // client-side duplicate-date guard
                            if (sel.length !== (new Set(sel)).size) {
                                const msg = 'Duplicate dates were detected in your selection. Please remove duplicates.';
                                if (window.Swal) { window.Swal.fire({ icon: 'warning', title: 'Duplicate dates', text: msg }); } else { alert(msg); }
                                return;
                            }

                            if (checked.includes('Vacation Leave')) {
                                if (!sel.length) {
                                    const msg = 'Please select dates for your Vacation Leave.';
                                    if (window.Swal) { window.Swal.fire({ icon: 'warning', title: 'Missing dates', text: msg }); } else { alert(msg); }
                                    return;
                                }
                                    const today = new Date(); today.setHours(0,0,0,0);
                                    const minStart = new Date(today.getTime()); minStart.setDate(minStart.getDate() + 5);
                                    const allocErrors = [];
                                    sel.forEach(d => {
                                        const selTypeEl = document.querySelector(`select[name="allocation[${d}][type]"]`);
                                        const typeVal = selTypeEl ? selTypeEl.value : (checked[0] || null);
                                        if (typeVal === 'Vacation Leave') {
                                            const dt = new Date(d); dt.setHours(0,0,0,0);
                                            if (dt < minStart) allocErrors.push(d);
                                        }
                                    });
                                    if (allocErrors.length) {
                                        const msg = 'Vacation Leave selected for these dates requires filing at least 5 calendar days before the start date: ' + allocErrors.join(', ');
                                        if (window.Swal) { window.Swal.fire({ icon: 'warning', title: 'Invalid start date', text: msg }); } else { alert(msg); }
                                        return;
                                    }
                            }

                            const reasonEl = document.querySelector('textarea[name="reason"]');
                            const reasonVal = reasonEl ? (reasonEl.value || '').trim() : '';
                            const isRangeMode = (document.getElementById('extendedLeaveModeInput') || {}).value === '1';
                            const needsDetailFor = isRangeMode ? [] : checked.filter(v => /vacation|special leave|special|spl/i.test(v));
                            const needsStudy = checked.filter(v => /study/i.test(v));
                            const needsStudyReason = needsStudy.slice();
                            const needsSick = isRangeMode ? [] : checked.filter(v => /sick/i.test(v));
                            const msgs = [];

                            if (isRangeMode) {
                                // Range mode: require start and end dates
                                const rs = document.getElementById('rangeStart');
                                const re = document.getElementById('rangeEnd');
                                if (!rs || !rs.value) { msgs.push('Please select a start date for your leave.'); }
                                if (!re || !re.value) { msgs.push('Please select an end date for your leave.'); }
                            }

                            if (needsDetailFor.length) {
                                // require 6.B details: either radio selected (details_location) or location specify filled
                                const locRadio = document.querySelector('input[name="details_location"]:checked');
                                const locSpecify = (document.getElementById('detailsLocationSpecify') && document.getElementById('detailsLocationSpecify').value || '').trim();
                                if (!locRadio && !locSpecify) {
                                    msgs.push('6.B Details of Leave (Within/Abroad) required for Vacation/Special Leave.');
                                }
                                if (!reasonVal) msgs.push('Reason / Purpose is required for Vacation/Special Leave.');
                            }

                            if (needsStudy.length) {
                                // require study details (checkboxes or specify) and Reason
                                const studyChecks = document.querySelectorAll('input[name="details_study_purpose[]"]:checked');
                                const studyOther = (document.getElementById('detailsStudyOther') && document.getElementById('detailsStudyOther').value || '').trim();
                                if ((!studyChecks || studyChecks.length === 0) && !studyOther) {
                                    msgs.push('6.B Study details required for Study Leave (select a purpose or specify).');
                                }
                                if (!reasonVal) msgs.push('Reason / Purpose is required for Study Leave.');
                            }

                            if (needsSick.length) {
                                const sickRadio = document.querySelector('input[name="details_sick_treatment"]:checked');
                                const sickIllness = (document.getElementById('detailsSickIllness') && document.getElementById('detailsSickIllness').value || '').trim();
                                if (!sickRadio && !sickIllness) {
                                    msgs.push('6.B Details of Leave (In Hospital / Out Patient) required for Sick Leave.');
                                }
                            }

                            if (msgs.length) {
                                let inline = document.getElementById('formInlineMsg');
                                if (!inline) {
                                    inline = document.createElement('div');
                                    inline.id = 'formInlineMsg';
                                    inline.style.color = '#b91c1c';
                                    inline.style.margin = '8px 0';
                                    inline.style.fontWeight = '600';
                                    const formContainer = form.querySelector('.tile');
                                    form.insertBefore(inline, form.firstChild);
                                }
                                inline.textContent = msgs.join(' ');
                                inline.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                return;
                            }

                            if (window.Swal) {
                                window.Swal.fire({
                                    title: 'Are you sure you want to file this application?',
                                    icon: 'question',
                                    showCancelButton: true,
                                    confirmButtonText: 'Yes, submit',
                                    cancelButtonText: 'Cancel'
                                }).then((result) => {
                                    if (result.isConfirmed) {
                                        form.submit();
                                    }
                                });
                            } else {
                                if (confirm('Are you sure you want to file this application?')) {
                                    form.submit();
                                }
                            }
                        });
                    }
                });
                </script>
                <div class="tile" style="grid-column:1/-1;">
                    <!-- 6.B DETAILS OF LEAVE -->
                    <div class="details6b-block" id="details6BSection">
                                            <script>
                                            document.addEventListener('DOMContentLoaded', function () {
                                                const leaveTypeCheckboxes = document.querySelectorAll('input[name="leave_types[]"]');
                                                const detailsMap = {
                                                    'Vacation Leave': 'detailsVacationSpecial',
                                                    'Special Privilege Leave': 'detailsVacationSpecial',
                                                    'Sick Leave': 'detailsSick',
                                                    'Special Leave (Gynecological)': 'detailsWomen',
                                                    'Study / Examination Leave': 'detailsStudy',
                                                };
                                                const allSectionIds = [...new Set(Object.values(detailsMap))];
                                                const sectionEls = {};
                                                allSectionIds.forEach(id => {
                                                    sectionEls[id] = document.getElementById(id);
                                                });

                                                function updateDetails6BVisibility() {
                                                    Object.values(sectionEls).forEach(el => {
                                                        if (!el) return;
                                                        el.classList.remove('d-block');
                                                        el.classList.add('d-none');
                                                        el.style.display = 'none';
                                                    });
                                                    const checked = Array.from(leaveTypeCheckboxes).filter(cb => cb.checked).map(cb => cb.value);
                                                    const showIds = new Set();
                                                    checked.forEach(val => {
                                                        if (detailsMap[val]) showIds.add(detailsMap[val]);
                                                    });
                                                    showIds.forEach(id => {
                                                        if (sectionEls[id]) {
                                                            sectionEls[id].classList.remove('d-none');
                                                            sectionEls[id].classList.add('d-block');
                                                            sectionEls[id].style.display = 'block';
                                                        }
                                                    });
                                                    // Also show/hide the entire 6.B container
                                                    const container = document.getElementById('details6BSection');
                                                    if (container) {
                                                        container.style.display = showIds.size > 0 ? 'block' : 'none';
                                                    }
                                                }
                                                leaveTypeCheckboxes.forEach(cb => {
                                                    cb.addEventListener('change', updateDetails6BVisibility);
                                                });
                                                updateDetails6BVisibility();
                                            });
                                            </script>
                        <label class="mb-2" style="font-weight:600;"><strong>6.B Details of Leave</strong></label>

                        <div id="detailsVacationSpecial" class="mb-3 d-none">
                            <div class="font-weight-bold small mb-1">In case of Vacation / Special Privilege Leave</div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="details_location" id="detailsWithinPH" value="within_ph">
                                <label class="form-check-label" for="detailsWithinPH">Within the Philippines</label>
                            </div>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="radio" name="details_location" id="detailsAbroad" value="abroad">
                                <label class="form-check-label" for="detailsAbroad">Abroad</label>
                            </div>
                            <input type="text" class="form-control form-control-sm" name="details_location_specify" id="detailsLocationSpecify" placeholder="If abroad, specify country/place">
                        </div>

                        <div id="detailsSick" class="mb-3 d-none">
                            <div class="font-weight-bold small mb-1">In case of Sick Leave</div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="details_sick_treatment" id="detailsHospital" value="hospital">
                                <label class="form-check-label" for="detailsHospital">In Hospital</label>
                            </div>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="radio" name="details_sick_treatment" id="detailsOutpatient" value="outpatient">
                                <label class="form-check-label" for="detailsOutpatient">Out Patient</label>
                            </div>
                            <input type="text" class="form-control form-control-sm" name="details_sick_illness" id="detailsSickIllness" placeholder="Specify illness">
                        </div>

                        <div id="detailsWomen" class="mb-3 d-none">
                            <div class="font-weight-bold small mb-1">In case of Special Leave Benefits for Women</div>
                            <input type="text" class="form-control form-control-sm" name="details_women_illness" id="detailsWomenIllness" placeholder="Specify illness / surgery">
                        </div>

                        <div id="detailsStudy" class="mb-3 d-none">
                            <div class="font-weight-bold small mb-1">In case of Study Leave</div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="details_study_purpose[]" id="detailsStudyMasters" value="masters_completion">
                                <label class="form-check-label" for="detailsStudyMasters">Completion of Master's Degree</label>
                            </div>
                            <div class="form-check mb-1">
                                <input class="form-check-input" type="checkbox" name="details_study_purpose[]" id="detailsStudyBar" value="bar_board_review">
                                <label class="form-check-label" for="detailsStudyBar">BAR / Board Examination Review</label>
                            </div>
                            <input type="text" class="form-control form-control-sm" name="details_study_other" id="detailsStudyOther" placeholder="Other study purpose (optional)">
                        </div>
                    </div>
                </div>
                <div class="tile" style="grid-column:1/-1;">
                    <label style="font-weight:600; margin-bottom: 4px;">REASON / PURPOSE</label>
                    <textarea id="reasonInput" name="reason" rows="3" style="width:100%; resize:vertical; padding:10px; border-radius:8px; border:1.5px solid #cbd5e1; font-size:1em; text-transform:uppercase;" placeholder="State the main reason or purpose for your leave (e.g. 'Family emergency', 'Personal business', 'Medical appointment', etc.)"></textarea>
                </div>
                <div class="actions" style="grid-column:1/-1;">
                    <button type="submit" class="btn" style="min-width:200px;">Submit Leave Request</button>
                </div>
            </form>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const datePicker = document.getElementById('datePicker');
                    const addDateBtn = document.getElementById('addDateBtn');
                    const selectedDatesList = document.getElementById('selectedDatesList');
                    const leaveDatesInput = document.getElementById('leaveDatesInput');
                    const allocationSection = document.getElementById('allocationSection');
                    const leaveTypeCheckboxes = document.querySelectorAll('input[name="leave_types[]"]');
                    let selectedDates = [];

                    function getCheckedLeaveTypes() {
                        return Array.from(leaveTypeCheckboxes).filter(cb => cb.checked).map(cb => ({
                            value: cb.value,
                            label: cb.parentElement.textContent.trim()
                        }));
                    }

                    function renderDates() {
                        selectedDatesList.innerHTML = '';
                        selectedDates.forEach((date, idx) => {
                            const row = document.createElement('div');
                            row.style.display = 'flex';
                            row.style.alignItems = 'center';
                            row.style.marginBottom = '6px';
                            const span = document.createElement('span');
                            span.textContent = date;
                            span.style.flex = '1';
                            const btn = document.createElement('button');
                            btn.type = 'button';
                            btn.textContent = '×';
                            btn.className = 'btn';
                            btn.style.background = '#ef4444';
                            btn.style.color = '#fff';
                            btn.style.fontWeight = 'bold';
                            btn.style.fontSize = '1em';
                            btn.style.marginLeft = '8px';
                            btn.style.padding = '2px 10px';
                            btn.onclick = function () {
                                selectedDates.splice(idx, 1);
                                renderDates();
                                renderAllocationSection();
                            };
                            row.appendChild(span);
                            row.appendChild(btn);
                            selectedDatesList.appendChild(row);
                        });
                        leaveDatesInput.value = selectedDates.join(',');
                        renderAllocationSection();
                    }

                    function renderAllocationSection() {
                        allocationSection.innerHTML = '';
                        const leaveTypes = getCheckedLeaveTypes();
                        if (!selectedDates.length || leaveTypes.length === 0) {
                            allocationSection.style.display = 'none';
                            return;
                        }
                        allocationSection.style.display = '';
                        let html = `<div class="table-responsive">
                            <table class="table table-bordered" style="min-width:480px;">
                                <thead>
                                    <tr>
                                        <th style="padding:10px 18px;">Date</th>
                                        <th style="padding:10px 18px;">Type</th>
                                        <th style="padding:10px 18px;">Days</th>
                                    </tr>
                                </thead>
                                <tbody>`;
                        selectedDates.forEach(date => {
                            html += '<tr>';
                            html += '<td style="padding:10px 18px;">' + date + '</td>';
                            html += '<td style="padding:10px 18px;"><select name="allocation['+date+'][type]" class="form-select">';
                            leaveTypes.forEach(type => {
                                html += '<option value="'+type.value+'">'+type.label+'</option>';
                            });
                            html += '</select></td>';
                            html += '<td style="padding:10px 18px;"><select name="allocation['+date+'][days]" class="form-select">';
                            html += '<option value="1">1</option><option value="0.5">0.5</option>';
                            html += '</select></td>';
                            html += '</tr>';
                        });
                        html += '</tbody></table></div>';
                        allocationSection.innerHTML = html;
                    }

                    function updateDatePickerState() {
                        const val = datePicker.value;
                        const datePickerMsg = document.getElementById('datePickerMsg');
                        if (!val) {
                            addDateBtn.disabled = false;
                            if (datePickerMsg) { datePickerMsg.style.display = 'none'; }
                            return;
                        }
                        const d = new Date(val); d.setHours(0,0,0,0);
                        // if (d.getDay() === 0 || d.getDay() === 6) {
                        //     addDateBtn.disabled = true;
                        //     if (datePickerMsg) { datePickerMsg.style.display = ''; datePickerMsg.textContent = 'Weekends are excluded.'; }
                        //     return;
                        // }
                        const checkedTypes = getCheckedLeaveTypes().map(t => t.value);
                        if (checkedTypes.includes('Vacation Leave')) {
                            const today = new Date(); today.setHours(0,0,0,0);
                            const minStart = new Date(today.getTime()); minStart.setDate(minStart.getDate() + 5);
                            if (d < minStart) {
                                if (datePickerMsg) { datePickerMsg.style.display = 'block'; datePickerMsg.textContent = 'Vacation Leave must be filed at least 5 calendar days before the start date.'; }
                                return;
                            }
                        }
                        if (checkedTypes.includes('Mandatory/Forced Leave')) {
                            const today = new Date(); today.setHours(0,0,0,0);
                            const minStart = new Date(today.getTime()); minStart.setDate(minStart.getDate() + 5);
                            if (d < minStart) {
                                if (datePickerMsg) { datePickerMsg.style.display = 'block'; datePickerMsg.textContent = 'Mandatory/Forced Leave must be filed at least 5 calendar days before the start date.'; }
                                return;
                            }
                            const month = d.getMonth(); // 0-indexed: 10 = November, 11 = December
                            if (month !== 10 && month !== 11) {
                                if (datePickerMsg) { datePickerMsg.style.display = 'block'; datePickerMsg.textContent = 'Mandatory/Forced Leave dates must fall in November or December.'; }
                                return;
                            }
                        }
                        if (datePickerMsg) { datePickerMsg.style.display = 'none'; }
                    }

                    datePicker.addEventListener('change', updateDatePickerState);

                    addDateBtn.onclick = function () {
                        const val = datePicker.value;
                        if (!val) {
                            if (window.Swal) { window.Swal.fire({ icon: 'warning', title: 'Missing date', text: 'Please select a date.' }); } else { alert('Please select a date.'); }
                            return;
                        }
                        if (selectedDates.includes(val)) {
                            if (window.Swal) { window.Swal.fire({ icon: 'warning', title: 'Duplicate date', text: 'Date already selected.' }); } else { alert('Date already selected.'); }
                            return;
                        }
                        const d = new Date(val); d.setHours(0,0,0,0);
                        // if (d.getDay() === 0 || d.getDay() === 6) {
                        //     const checked = getCheckedLeaveTypes().map(t => t.value);
                        //     if (checked.includes('Vacation Leave')) {
                        //         if (window.Swal) { window.Swal.fire({ icon: 'warning', title: 'Invalid date', text: 'Weekends are excluded. Vacation Leave still requires calendar days; you cannot add weekend dates here.' }); } else { alert('Weekends are excluded. Vacation Leave still requires calendar days; you cannot add weekend dates here.'); }
                        //     } else {
                        //         if (window.Swal) { window.Swal.fire({ icon: 'warning', title: 'Invalid date', text: 'Weekends are excluded.' }); } else { alert('Weekends are excluded.'); }
                        //     }
                        //     return;
                        // }
                        const checkedTypes = getCheckedLeaveTypes().map(t => t.value);
                        if (checkedTypes.includes('Vacation Leave')) {
                            const today = new Date(); today.setHours(0,0,0,0);
                            const minStart = new Date(today.getTime()); minStart.setDate(minStart.getDate() + 5);
                            if (d < minStart) { if (window.Swal) { window.Swal.fire({ icon: 'warning', title: 'Invalid date', text: 'Vacation Leave must be filed at least 5 calendar days before the start date.' }); } else { alert('Vacation Leave must be filed at least 5 calendar days before the start date.'); } return; }
                        }
                        if (checkedTypes.includes('Mandatory/Forced Leave')) {
                            const today = new Date(); today.setHours(0,0,0,0);
                            const minStart = new Date(today.getTime()); minStart.setDate(minStart.getDate() + 5);
                            if (d < minStart) { if (window.Swal) { window.Swal.fire({ icon: 'warning', title: 'Invalid date', text: 'Mandatory/Forced Leave must be filed at least 5 calendar days before the start date.' }); } else { alert('Mandatory/Forced Leave must be filed at least 5 calendar days before the start date.'); } return; }
                            const month = d.getMonth();
                            if (month !== 10 && month !== 11) { if (window.Swal) { window.Swal.fire({ icon: 'warning', title: 'Invalid date', text: 'Mandatory/Forced Leave dates must fall in November or December.' }); } else { alert('Mandatory/Forced Leave dates must fall in November or December.'); } return; }
                        }
                        selectedDates.push(val);
                        selectedDates.sort();
                        renderDates();
                        datePicker.value = '';
                        updateDatePickerState();
                    };

                    const EXCLUSIVE_TYPES = [
                        'Maternity Leave', 'Paternity Leave', 'Adoption Leave',
                        'VAWC Leave', 'Special Leave (Gynecological)',
                        'Rehabilitation Privilege', 'Study / Examination Leave',
                        'Mandatory/Forced Leave',
                    ];
                    const RANGE_TYPES = [
                        'Maternity Leave', 'VAWC Leave', 'Special Leave (Gynecological)',
                        'Rehabilitation Privilege', 'Study / Examination Leave',
                    ];

                    function updateLeaveTypeMode() {
                        const checkedVals = Array.from(leaveTypeCheckboxes).filter(c => c.checked).map(c => c.value);
                        const isRange = checkedVals.some(v => RANGE_TYPES.includes(v));
                        const individualSec = document.getElementById('individualDateSection');
                        const rangeSec = document.getElementById('rangeSection');
                        const extInput = document.getElementById('extendedLeaveModeInput');
                        if (isRange) {
                            if (individualSec) individualSec.style.display = 'none';
                            if (rangeSec) rangeSec.style.display = '';
                            if (extInput) extInput.value = '1';
                        } else {
                            if (individualSec) individualSec.style.display = '';
                            if (rangeSec) rangeSec.style.display = 'none';
                            if (extInput) extInput.value = '0';
                        }
                    }

                    leaveTypeCheckboxes.forEach(cb => {
                        cb.addEventListener('change', function () {
                            const isExclusive = EXCLUSIVE_TYPES.includes(this.value);
                            const checkedAll = Array.from(leaveTypeCheckboxes).filter(c => c.checked);
                            const exclusiveNotice = document.getElementById('exclusiveNotice');

                            if (isExclusive && this.checked) {
                                // Uncheck and disable every other checkbox
                                leaveTypeCheckboxes.forEach(c => {
                                    if (c !== this) { c.checked = false; c.disabled = true; }
                                });
                                if (exclusiveNotice) {
                                    exclusiveNotice.textContent = this.value + ' must be filed as a separate application. Uncheck it to select other types.';
                                    exclusiveNotice.style.display = '';
                                }
                            } else if (isExclusive && !this.checked) {
                                // Re-enable all (except month-restricted checkboxes)
                                leaveTypeCheckboxes.forEach(c => { if (c.dataset.monthRestricted !== 'true') c.disabled = false; });
                                if (exclusiveNotice) exclusiveNotice.style.display = 'none';
                            } else {
                                // Regular type: enforce 3-type max
                                const regularChecked = Array.from(leaveTypeCheckboxes).filter(c => c.checked && !EXCLUSIVE_TYPES.includes(c.value));
                                if (regularChecked.length >= 3) {
                                    leaveTypeCheckboxes.forEach(c => {
                                        if (!c.checked) c.disabled = true;
                                    });
                                } else {
                                    leaveTypeCheckboxes.forEach(c => { if (c.dataset.monthRestricted !== 'true' && (!EXCLUSIVE_TYPES.includes(c.value) || !Array.from(leaveTypeCheckboxes).some(x => x.checked && EXCLUSIVE_TYPES.includes(x.value)))) c.disabled = false; });
                                }
                                if (exclusiveNotice) exclusiveNotice.style.display = 'none';
                            }

                            updateLeaveTypeMode();
                            renderAllocationSection();
                        });
                    });

                    // Range date pickers - compute weekday count live
                    const rangeStart = document.getElementById('rangeStart');
                    const rangeEnd = document.getElementById('rangeEnd');
                    const rangeDaysCount = document.getElementById('rangeDaysCount');

                    function computeRangeDays() {
                        if (!rangeStart || !rangeEnd || !rangeStart.value || !rangeEnd.value) {
                            if (rangeDaysCount) rangeDaysCount.textContent = '-';
                            return;
                        }
                        let s = new Date(rangeStart.value); s.setHours(0,0,0,0);
                        let e = new Date(rangeEnd.value); e.setHours(0,0,0,0);
                        if (e < s) { if (rangeDaysCount) rangeDaysCount.textContent = 'End date must be after start date'; return; }
                        let count = 0;
                        let cur = new Date(s);
                        while (cur <= e) { if (cur.getDay() !== 0 && cur.getDay() !== 6) count++; cur.setDate(cur.getDate() + 1); }
                        if (rangeDaysCount) rangeDaysCount.textContent = count + (count === 1 ? ' day' : ' days');
                    }

                    if (rangeStart) rangeStart.addEventListener('change', computeRangeDays);
                    if (rangeEnd) rangeEnd.addEventListener('change', computeRangeDays);

                    renderDates();
                    updateLeaveTypeMode();
                });
            </script>
            <script>
                document.addEventListener('DOMContentLoaded', function () {
                    const reason = document.getElementById('reasonInput');
                    if (!reason) return;
                    reason.addEventListener('input', function (e) {
                        const pos = this.selectionStart;
                        this.value = (this.value || '').toUpperCase();
                        try { this.setSelectionRange(pos, pos); } catch (e) {}
                    });
                    const form = reason.closest('form');
                    if (form) {
                        form.addEventListener('submit', function () {
                            reason.value = (reason.value || '').toUpperCase();
                        });
                    }
                });
            </script>
        </section>

        {{-- Leave Request Tracking & History --}}
        <section>
            <x-hris.table-layout
                title="My Leave Requests"
                subtitle="Review and manage your submitted leave requests."
                :paginator="$leaveRequests"
                :showExport="false"
                :stickyFilters="true"
                :scrollableTable="true"
                :showTopPagination="true"
            >
                <x-slot:filters>
                    <div class="hris-filter-left" style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
                        <x-hris.month-filter name="month" />
                        @php
                            $currentYear = (int) request('year', date('Y'));
                        @endphp
                        <div class="hris-filter-group">
                            <label class="hris-filter-label">Year</label>
                            <form id="year-filter-form" method="GET">
                                <select name="year" class="hris-filter-select" onchange="document.getElementById('year-filter-form').submit()">
                                    @for($y = date('Y'); $y >= 2024; $y--)
                                        <option value="{{ $y }}" {{ $currentYear === $y ? 'selected' : '' }}>{{ $y }}</option>
                                    @endfor
                                </select>
                                @foreach(request()->query() as $key => $value)
                                    @if($key !== 'year' && $key !== 'page')
                                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                                    @endif
                                @endforeach
                            </form>
                        </div>
                    </div>
                    <div class="hris-filter-right">
                        <x-hris.search-bar />
                    </div>
                </x-slot:filters>
                @php
                    $currentSort = request('sort');
                    $currentDir = strtolower(request('dir', 'desc')) === 'asc' ? 'asc' : 'desc';
                    $sortUrl = function ($column) use ($currentSort, $currentDir) {
                        $params = request()->except('page');
                        $params['sort'] = $column;
                        $params['dir'] = ($currentSort === $column && $currentDir === 'asc') ? 'desc' : 'asc';
                        return request()->url() . '?' . http_build_query($params);
                    };
                    $activeClass = function ($column) use ($currentSort) {
                        return $currentSort === $column ? 'text-blue-600 font-semibold' : 'text-slate-600';
                    };
                    $_role = strtolower(str_replace(['-', '_'], ' ', trim((string)(optional(auth()->user())->access_level ?? ''))));
                    $canPrintOnApproval = str_contains($_role, 'department head') || str_contains($_role, 'hr manager');
                @endphp

                <table class="hris-table my-requests-table">
                    <thead>
                        <tr>
                            <th><a href="{{ $sortUrl('leave_type') }}" class="{{ $activeClass('leave_type') }}">Type</a></th>
                            <th><a href="{{ $sortUrl('start_date') }}" class="{{ $activeClass('start_date') }}">Dates</a></th>
                            <th><a href="{{ $sortUrl('total_days') }}" class="{{ $activeClass('total_days') }}">No of days</a></th>
                            <th><a href="{{ $sortUrl('status') }}" class="{{ $activeClass('status') }}">Status</a></th>
                            <th>Remarks</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse($leaveRequests as $leave)
                            @php
                                $s = $leave->start_date ? \Carbon\Carbon::parse($leave->start_date)->format('M d, Y') : '';
                                $e = $leave->end_date ? \Carbon\Carbon::parse($leave->end_date)->format('M d, Y') : '';
                            @endphp
                            <tr id="leave-row-{{ $leave->id }}"
                                data-employee="{{ $leave->user->name ?? '-' }}"
                                data-type="{{ $leave->leave_type }}"
                                data-period="{{ $s }}@if($e) to {{ $e }}@endif"
                                data-total="{{ $leave->total_days ?? '-' }}"
                                data-filed="{{ $leave->created_at ? $leave->created_at->format('M d, Y') : '-' }}"
                                data-reason="{{ $leave->reason ?? '' }}"
                                data-status="{{ $leave->status }}"
                                data-remarks="{{ $leave->remarks ?? '' }}"
                                @if($leave->status === 'cancelled') style="opacity:.7;text-decoration:line-through" @endif>
                                <td>{{ $leave->leave_type }}</td>
                                <td>{{ $s }}@if($e) to {{ $e }}@endif</td>
                                <td>{{ $leave->total_days ?? (($leave->start_date && $leave->end_date) ? (\Carbon\Carbon::parse($leave->start_date)->diffInDays(\Carbon\Carbon::parse($leave->end_date)) + 1) : '-') }}</td>
                                <td><x-hris.status-badge :status="$leave->status" /></td>
                                <td>
                                    @if($leave->status === 'cancelled')
                                        {{ $leave->remarks ?: 'Cancelled by applicant' }}
                                    @elseif(in_array($leave->status, ['rejected', 'declined']))
                                        {{ $leave->rejection_notes ?? ($leave->remarks ?? '-') }}
                                    @else
                                        {{ $leave->remarks ?? '-' }}
                                    @endif
                                </td>
                                <td>
                                    <div class="action-btns">
                                        <button type="button" class="hris-btn hris-btn-secondary" onclick="openLeaveModal({{ $leave->id }})">View</button>
                                        @if(!in_array($leave->status, ['cancelled','rejected','declined']))
                                            @php
                                                $_printedAt = $leave->last_printed_at?->format('M d, Y');
                                                $_printedBy = optional($leave->lastPrintedBy)->name;
                                            @endphp
                                            @if(!empty($leave->printing_allowed) || ($canPrintOnApproval && $leave->status === 'approved'))
                                                <button class="hris-btn hris-btn-primary" id="print-btn-{{ $leave->id }}"
                                                    onclick="confirmLeavePrint('{{ route('employee.leave.print.single', $leave->id) }}', {{ json_encode($_printedAt) }}, {{ json_encode($_printedBy) }})">Print</button>
                                            @else
                                                <button class="hris-btn hris-btn-secondary" disabled title="Printing enabled after Allow Printing." id="print-btn-{{ $leave->id }}">Print</button>
                                            @endif
                                            @if($leave->status === 'pending')
                                                <button type="button" class="hris-btn hris-btn-danger" onclick="openPendingCancelModal({{ $leave->id }})">Cancel</button>
                                            @endif
                                            @if($leave->status === 'approved')
                                                @if(in_array($leave->cancellation_status, ['Pending Cancellation', 'DH Recommended', 'AO Endorsed']))
                                                    @php
                                                        $chipMap = [
                                                            'Pending Cancellation' => ['text' => 'Awaiting DH Review',        'bg' => '#fef9c3', 'color' => '#854d0e', 'border' => '#fde047'],
                                                            'DH Recommended'       => ['text' => 'Awaiting AO Endorsement',   'bg' => '#dbeafe', 'color' => '#1e40af', 'border' => '#93c5fd'],
                                                            'AO Endorsed'          => ['text' => 'Awaiting Leave Manager',    'bg' => '#ede9fe', 'color' => '#5b21b6', 'border' => '#c4b5fd'],
                                                        ];
                                                        $chip = $chipMap[$leave->cancellation_status] ?? null;
                                                    @endphp
                                                    @if($chip)
                                                        <span style="background:{{ $chip['bg'] }};color:{{ $chip['color'] }};border:1px solid {{ $chip['border'] }};padding:3px 10px;border-radius:12px;font-size:0.75rem;font-weight:600;white-space:nowrap">{{ $chip['text'] }}</span>
                                                    @endif
                                                @elseif($leave->cancellation_status === 'Rejected')
                                                    <span style="background:#fee2e2;color:#991b1b;border:1px solid #fca5a5;padding:3px 10px;border-radius:12px;font-size:0.75rem;font-weight:600;white-space:nowrap">Cancellation Rejected</span>
                                                    <button type="button" class="hris-btn hris-btn-warning" onclick="openCancellationRequestModal({{ $leave->id }})">Cancel</button>
                                                @else
                                                    <button type="button" class="hris-btn hris-btn-warning" onclick="openCancellationRequestModal({{ $leave->id }})">Cancel</button>
                                                @endif
                                            @endif
                                            @if($leave->status === 'approved' && in_array($leave->leave_type, ['Vacation Leave', 'Sick Leave', 'Wellness Leave']) && !$leave->rescheduled_from_id)
                                                @if($leave->reschedule_status === 'Pending Reschedule')
                                                    <span class="hris-badge" style="background:#fef3c7;color:#92400e;border:1px solid #fbbf24;padding:4px 8px;border-radius:4px;font-size:0.75rem;white-space:nowrap">Reschedule Pending</span>
                                                @else
                                                    <button type="button" class="hris-btn hris-btn-secondary" onclick="openRescheduleModal({{ $leave->id }}, {{ json_encode($leave->leave_type) }}, {{ (float) $leave->total_days }})">Reschedule</button>
                                                @endif
                                            @endif
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center text-muted">No leave requests found for the selected filters.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </x-hris.table-layout>
        </section>
    </div>
@endsection
@section('modals')
<dialog id="leaveModal" class="employee-modal">
    <div class="modal-top-actions" style="justify-content:space-between;align-items:center">
        <div>
            <h3 id="leave-modal-title" style="margin:0">My Leave Request</h3>
            <div class="record-email" style="font-size:0.9rem;color:#64748b">View details for your leave request</div>
        </div>
        <div id="leave-modal-actions" style="display:flex;gap:8px;align-items:center">
        </div>
    </div>
    <div id="leave-modal-body" style="margin-top:8px;">
    </div>
    <form method="dialog" class="modal-actions" style="margin-top:12px; text-align:right">
        <button class="btn" type="submit">Close</button>
    </form>
</dialog>
<dialog id="cancellationRequestModal" class="employee-modal">
    <form id="cancellationRequestForm" method="POST" class="modal-body" style="min-width:320px;">
        @csrf
        <h3 style="margin-top:0">Request Leave Cancellation</h3>
        <p class="muted">Provide a reason for cancelling your approved leave. Your request will be reviewed by the Department Head, then the Administrative Officer, then the Leave Manager.</p>
        <div style="margin-top:8px">
            <label style="font-weight:600; display:block; margin-bottom:8px">Reason for Cancellation</label>
            <div id="cancelReasonChips" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
                <button type="button" class="cancel-reason-chip" data-reason="Reported to work" style="border:1px solid #cbd5e1;border-radius:20px;padding:4px 14px;font-size:0.82rem;background:#fff;cursor:pointer">Reported to work</button>
                <button type="button" class="cancel-reason-chip" data-reason="Personal reasons" style="border:1px solid #cbd5e1;border-radius:20px;padding:4px 14px;font-size:0.82rem;background:#fff;cursor:pointer">Personal reasons</button>
                <button type="button" class="cancel-reason-chip" data-reason="" style="border:1px solid #cbd5e1;border-radius:20px;padding:4px 14px;font-size:0.82rem;background:#fff;cursor:pointer">Other</button>
            </div>
            <textarea name="reason" id="cancellationReasonInput" rows="4" style="width:100%; padding:10px; border-radius:6px; border:1px solid #ddd" required></textarea>
        </div>
        <div class="modal-actions" style="margin-top:12px; text-align:right">
            <button type="button" class="btn" onclick="closeCancellationModal()">Close</button>
            <button type="submit" class="btn" style="background:#ef4444; color:#fff">Submit Request</button>
        </div>
    </form>
</dialog>
<dialog id="pendingCancelModal" class="employee-modal">
    <form id="pendingCancelForm" method="POST" class="modal-body" style="min-width:320px;">
        @csrf
        @method('PATCH')
        <h3 style="margin-top:0">Cancel Pending Leave</h3>
        <p class="muted">Please provide a reason for cancelling this pending leave. This will be recorded for audit purposes.</p>
        <div style="margin-top:8px">
            <label style="font-weight:600; display:block; margin-bottom:6px">Reason for Cancellation</label>
            <textarea name="remarks" id="pendingCancelRemarks" rows="4" style="width:100%; padding:10px; border-radius:6px; border:1px solid #ddd" required></textarea>
        </div>
        <div class="modal-actions" style="margin-top:12px; text-align:right">
            <button type="button" class="btn" onclick="closePendingCancelModal()">Close</button>
            <button type="submit" class="btn" style="background:#ef4444; color:#fff">Confirm Cancel</button>
        </div>
    </form>
</dialog>

{{-- Reschedule Request Modal --}}
<dialog id="rescheduleModal" class="employee-modal" style="max-width:600px;width:95%">
    <form id="rescheduleForm" method="POST" class="modal-body">
        @csrf
        <input type="hidden" id="rsLeaveName" name="leave_types[]" value="">
        <input type="hidden" id="rsDatesInput" name="leave_dates" value="">

        <h3 style="margin-top:0">Reschedule Leave</h3>
        <p class="muted">Select new dates for your <strong id="rsLeaveTypeLabel"></strong> leave. Your request will go through the approval process again.</p>

        <div style="margin-top:16px">
            <label style="font-weight:600;display:block;margin-bottom:6px">Select New Date(s)</label>
            <div style="display:flex;gap:8px;align-items:center">
                <input type="date" id="rsDatePicker" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none" style="flex:1">
                <button type="button" class="btn" onclick="rsAddDate()" style="white-space:nowrap">Add Date</button>
            </div>
            <div id="rsDateList" style="margin-top:12px"></div>
            <p id="rsNoDateMsg" style="color:#6b7280;font-size:0.875rem;margin-top:8px">No dates selected yet.</p>
        </div>

        {{-- Allocation table (rendered by JS) --}}
        <div id="rsAllocationSection" style="margin-top:16px;display:none">
            <label style="font-weight:600;display:block;margin-bottom:6px">Day Allocation per Date</label>
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:0.875rem" id="rsAllocTable">
                    <thead>
                        <tr style="background:#f8fafc">
                            <th style="padding:8px;border:1px solid #e2e8f0;text-align:left">Date</th>
                            <th style="padding:8px;border:1px solid #e2e8f0;text-align:center">Days</th>
                            <th style="padding:8px;border:1px solid #e2e8f0;text-align:center">Remove</th>
                        </tr>
                    </thead>
                    <tbody id="rsAllocBody"></tbody>
                    <tfoot>
                        <tr>
                            <td style="padding:8px;border:1px solid #e2e8f0;font-weight:600">Total</td>
                            <td style="padding:8px;border:1px solid #e2e8f0;text-align:center;font-weight:600" id="rsTotalDays">0</td>
                            <td style="border:1px solid #e2e8f0"></td>
                        </tr>
                        <tr id="rsTotalDaysWarningRow" style="display:none">
                            <td colspan="3" style="padding:6px 8px;border:1px solid #fca5a5;background:#fef2f2;color:#b91c1c;font-size:0.8rem">
                                Total cannot exceed original leave total (<span id="rsMaxDaysLabel"></span> day(s)).
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <div style="margin-top:16px">
            <label style="font-weight:600;display:block;margin-bottom:6px">Reason <span style="font-weight:400;color:#6b7280">(optional)</span></label>
            <textarea name="reason" id="rsReason" rows="3" style="width:100%;padding:10px;border-radius:6px;border:1px solid #ddd;box-sizing:border-box" placeholder="State your reason for rescheduling..."></textarea>
        </div>

        <div class="modal-actions" style="margin-top:16px;text-align:right">
            <button type="button" class="btn" onclick="closeRescheduleModal()">Cancel</button>
            <button type="submit" id="rsSubmitBtn" class="btn" style="background:#3b82f6;color:#fff" disabled>Submit Reschedule</button>
        </div>
    </form>
</dialog>
@endsection

@section('page_scripts_after')
<script>
function confirmLeavePrint(url, printedAt, printedBy) {
    if (!printedAt) {
        window.open(url, '_blank');
        return;
    }
    var msg = 'This leave form was already printed on <strong>' + printedAt + '</strong>';
    if (printedBy) msg += ' by <strong>' + printedBy + '</strong>';
    msg += '.<br>Do you still want to print a copy?';
    Swal.fire({
        title: 'Already Printed',
        html: msg,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: 'Yes, print anyway',
        cancelButtonText: 'Cancel',
        confirmButtonColor: '#2563eb',
    }).then(function (result) {
        if (result.isConfirmed) window.open(url, '_blank');
    });
}

function openLeaveModal(id) {
    const row = document.getElementById(`leave-row-${id}`);
    if (!row) return alert('Details not available');
    const modal = document.getElementById('leaveModal');
    const body = document.getElementById('leave-modal-body');
    const title = document.getElementById('leave-modal-title');
    body.innerHTML = '';
    title.textContent = 'My Leave Request Details';
    const employee = row.getAttribute('data-employee') || '';
    const typeLabel = row.getAttribute('data-type') || '';
    const period = row.getAttribute('data-period') || '';
    const total = row.getAttribute('data-total') || '';
    const filed = row.getAttribute('data-filed') || '';
    const reason = row.getAttribute('data-reason') || '';
    const status = row.getAttribute('data-status') || '';
    const remarks = row.getAttribute('data-remarks') || '';

    body.innerHTML = `<table style="width:100%;border-collapse:collapse"><tbody>
        <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Employee</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${employee}</td></tr>
        <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Leave Type</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${typeLabel}</td></tr>
        <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Period</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${period}</td></tr>
        <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Total Days</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${total}</td></tr>
        <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Filed At</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${filed}</td></tr>
        <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Status</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${status}</td></tr>
        <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Remarks</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${remarks || '-'}</td></tr>
        <tr><td style="padding:8px;border:1px solid #f1f5f9"><strong>Reason</strong></td><td style="padding:8px;border:1px solid #f1f5f9">${reason || '-'}</td></tr>
    </tbody></table>`;

    // Populate action buttons
    const actions = document.getElementById('leave-modal-actions');
    actions.innerHTML = '';

    if (status === 'pending') {
        const cancelBtn = document.createElement('button');
        cancelBtn.className = 'btn';
        cancelBtn.style.background = '#ef4444';
        cancelBtn.type = 'button';
        cancelBtn.textContent = 'Cancel';
        cancelBtn.onclick = function () {
            const form = document.querySelector(`#leave-row-${id} .cancel-leave-form`);
            if (form) {
                if (window.Swal) {
                    // close the dialog so SweetAlert2 is not visually overlapped
                    if (modal && typeof modal.close === 'function') modal.close();
                    window.Swal.fire({
                        title: 'Cancel leave request?',
                        text: 'Are you sure you want to cancel this leave request?',
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonText: 'Yes, cancel',
                        cancelButtonText: 'Keep'
                    }).then(result => {
                        if (result.isConfirmed) {
                            form.submit();
                        } else {
                            // reopen the dialog if user chose to keep the request
                            if (modal && typeof modal.showModal === 'function') modal.showModal();
                        }
                    });
                } else {
                    if (confirm('Cancel this leave request?')) form.submit();
                }
            } else {
                alert('Cancel action not available.');
            }
        };
        actions.appendChild(cancelBtn);
    }

    if (modal && typeof modal.showModal === 'function') modal.showModal();
}
function openPendingCancelModal(id) {
    const modal = document.getElementById('pendingCancelModal');
    const form = document.getElementById('pendingCancelForm');
    form.action = `{{ url('employee/leave-management') }}/${id}/cancel`;
    document.getElementById('pendingCancelRemarks').value = '';
    modal.showModal();
}
function closePendingCancelModal() {
    const modal = document.getElementById('pendingCancelModal');
    try { modal.close(); } catch (e) {}
}
function closeLeaveModal() { const dlg = document.getElementById('leaveModal'); if (dlg && typeof dlg.close === 'function') dlg.close(); }
function openCancellationRequestModal(id) {
    const dlg = document.getElementById('cancellationRequestModal');
    const form = document.getElementById('cancellationRequestForm');
    const reason = document.getElementById('cancellationReasonInput');
    if (!dlg || !form) return alert('Cancellation dialog not available');
    form.action = `/employee/leave-management/${id}/request-cancellation`;
    reason.value = '';
    document.querySelectorAll('#cancelReasonChips .cancel-reason-chip').forEach(function (chip) {
        chip.style.borderColor = '#cbd5e1';
        chip.style.background = '#fff';
        chip.style.color = '';
        chip.style.fontWeight = '';
    });
    if (typeof dlg.showModal === 'function') dlg.showModal();
}
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#cancelReasonChips .cancel-reason-chip').forEach(function (chip) {
        chip.addEventListener('click', function () {
            document.querySelectorAll('#cancelReasonChips .cancel-reason-chip').forEach(function (c) {
                c.style.borderColor = '#cbd5e1';
                c.style.background = '#fff';
                c.style.color = '';
                c.style.fontWeight = '';
            });
            chip.style.borderColor = '#3b82f6';
            chip.style.background = '#eff6ff';
            chip.style.color = '#1d4ed8';
            chip.style.fontWeight = '600';
            const ta = document.getElementById('cancellationReasonInput');
            ta.value = chip.dataset.reason || '';
            if (!chip.dataset.reason) ta.focus();
        });
    });
});
function closeCancellationModal() { const dlg = document.getElementById('cancellationRequestModal'); if (dlg && typeof dlg.close === 'function') dlg.close(); }

// ---- Reschedule Modal ----
let _rsLeaveType = '';
let _rsDates = [];
let _rsMaxDays = 0;

function openRescheduleModal(id, leaveType, maxDays) {
    const dlg = document.getElementById('rescheduleModal');
    const form = document.getElementById('rescheduleForm');
    if (!dlg || !form) return alert('Reschedule dialog not available');
    form.action = `/employee/leave-management/${id}/reschedule`;
    _rsLeaveType = leaveType;
    _rsMaxDays = maxDays;
    _rsDates = [];
    document.getElementById('rsLeaveName').value = leaveType;
    document.getElementById('rsLeaveTypeLabel').textContent = leaveType;
    const rsMinDate = new Date();
    rsMinDate.setDate(rsMinDate.getDate() + 5);
    document.getElementById('rsDatePicker').min = rsMinDate.toISOString().split('T')[0];
    document.getElementById('rsDatePicker').value = '';
    document.getElementById('rsReason').value = '';
    rsRenderDates();
    if (typeof dlg.showModal === 'function') dlg.showModal();
}

function closeRescheduleModal() {
    const dlg = document.getElementById('rescheduleModal');
    if (dlg && typeof dlg.close === 'function') dlg.close();
}

function rsAddDate() {
    const picker = document.getElementById('rsDatePicker');
    const val = picker.value;
    if (!val) return;
    if (_rsDates.includes(val)) { alert('Date already added.'); return; }
    _rsDates.push(val);
    _rsDates.sort();
    picker.value = '';
    rsRenderDates();
}

function rsRemoveDate(d) {
    _rsDates = _rsDates.filter(x => x !== d);
    rsRenderDates();
}

function rsRenderDates() {
    const noMsg = document.getElementById('rsNoDateMsg');
    const allocSec = document.getElementById('rsAllocationSection');
    const body = document.getElementById('rsAllocBody');
    const datesInput = document.getElementById('rsDatesInput');
    const submitBtn = document.getElementById('rsSubmitBtn');

    if (_rsDates.length === 0) {
        noMsg.style.display = '';
        allocSec.style.display = 'none';
        datesInput.value = '';
        submitBtn.disabled = true;
        return;
    }

    noMsg.style.display = 'none';
    allocSec.style.display = '';
    submitBtn.disabled = false;
    datesInput.value = _rsDates.join(',');

    body.innerHTML = '';
    let total = 0;
    _rsDates.forEach(d => {
        const tr = document.createElement('tr');
        const dateObj = new Date(d + 'T00:00:00');
        const label = dateObj.toLocaleDateString('en-PH', { weekday: 'short', year: 'numeric', month: 'short', day: 'numeric' });
        tr.innerHTML = `
            <td style="padding:8px;border:1px solid #e2e8f0">${label}</td>
            <td style="padding:8px;border:1px solid #e2e8f0;text-align:center">
                <select name="allocation[${d}][days]" style="width:80px" onchange="rsUpdateTotal()">
                    <option value="1">1 day</option>
                    <option value="0.5">½ day</option>
                </select>
                <input type="hidden" name="allocation[${d}][type]" value="${_rsLeaveType}">
            </td>
            <td style="padding:8px;border:1px solid #e2e8f0;text-align:center">
                <button type="button" style="color:#ef4444;background:none;border:none;cursor:pointer;font-weight:700" onclick="rsRemoveDate('${d}')">✕</button>
            </td>`;
        body.appendChild(tr);
        total += 1;
    });

    rsUpdateTotal();
}

function rsUpdateTotal() {
    const selects = document.querySelectorAll('#rsAllocBody select[name$="[days]"]');
    let t = 0;
    selects.forEach(s => { t += parseFloat(s.value || 1); });
    document.getElementById('rsTotalDays').textContent = t % 1 === 0 ? t : t.toFixed(1);

    const exceeded = _rsMaxDays > 0 && t > _rsMaxDays;
    const warnRow = document.getElementById('rsTotalDaysWarningRow');
    document.getElementById('rsMaxDaysLabel').textContent = _rsMaxDays % 1 === 0 ? _rsMaxDays : _rsMaxDays.toFixed(1);
    warnRow.style.display = exceeded ? '' : 'none';
    document.getElementById('rsSubmitBtn').disabled = exceeded || _rsDates.length === 0;
}
</script>
@endsection

@section('page_scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            @if(session('success'))
                const msg = {!! json_encode(session('success')) !!};
                if (window.Swal) {
                    window.Swal.fire({ icon: 'success', title: 'Success', text: msg });
                } else {
                    alert(msg);
                }
            @endif

            @if(session('error'))
                const err = {!! json_encode(session('error')) !!};
                if (window.Swal) {
                    window.Swal.fire({ icon: 'error', title: 'Error', text: err });
                } else {
                    alert(err);
                }
            @endif

            @if($errors->any())
                const errs = {!! json_encode($errors->all()) !!};
                const errMsg = errs.join('\n');
                if (window.Swal) {
                    window.Swal.fire({ icon: 'error', title: 'Validation Error', text: errMsg });
                } else {
                    alert(errMsg);
                }
            @endif
        });
    </script>
    <script>
        // Polling: check printing_allowed for approved leaves with disabled print buttons
        (function () {
            const watches = new Map();

            function initWatches() {
                const rows = document.querySelectorAll('table.my-requests-table tbody tr[id^="leave-row-"]');
                rows.forEach(row => {
                    const idMatch = row.id.match(/leave-row-(\d+)/);
                    if (!idMatch) return;
                    const id = idMatch[1];
                    const printBtn = document.getElementById('print-btn-' + id);
                    if (!printBtn) return;
                    // watch any disabled print buttons (pending or approved) so they can be enabled in-place
                    if (printBtn.disabled) {
                        watches.set(id, { row });
                    }
                });
            }

            async function checkOnce(id, ctx) {
                try {
                    const res = await fetch(`/api/leave/${id}/status`, { credentials: 'same-origin' });
                    if (!res.ok) return;
                    const data = await res.json();
                    if (data && data.printing_allowed) {
                        // replace disabled button with live print button
                        const old = document.getElementById('print-btn-' + id);
                        if (old) {
                            const btn = document.createElement('button');
                            btn.className = old.className.replace(/btn-secondary|btn-disabled-print/, 'btn-primary');
                            btn.id = old.id;
                            btn.innerHTML = 'Print';
                            const url = `/dashboard/employee/leave/${id}/print`;
                            const printedAt = data.last_printed_at || null;
                            const printedBy = data.last_printed_by_name || null;
                            btn.onclick = function () { confirmLeavePrint(url, printedAt, printedBy); };
                            old.replaceWith(btn);
                        }
                        watches.delete(id);
                    }
                } catch (e) {
                    // ignore transient errors
                }
            }

            function poll() {
                if (watches.size === 0) return;
                for (const [id, ctx] of Array.from(watches.entries())) {
                    checkOnce(id, ctx);
                }
            }

            document.addEventListener('DOMContentLoaded', () => {
                initWatches();
                poll();
            });
            // also init immediately in case DOMContentLoaded already fired
            initWatches();
            poll();
            setInterval(poll, 5000);
        })();
    </script>
@endsection

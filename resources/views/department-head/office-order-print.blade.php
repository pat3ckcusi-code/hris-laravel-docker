<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Office Order — {{ $order->office_order_num ?? $order->id }}</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }

        @page { size: 8.5in 13in; margin: 0.8in; }

        body {
            font-family: 'Times New Roman', serif;
            font-size: 12pt;
            color: #000;
            background: #f1f5f9;
            line-height: 1.5;
        }

        .print-container {
            max-width: 8.5in;
            margin: 24px auto;
            background: #fff;
            padding: 56px 64px;
            box-shadow: 0 2px 10px rgba(0,0,0,.12);
        }

        .no-print { margin: 16px auto; max-width: 8.5in; text-align: center; }
        .no-print button {
            padding: 10px 18px; margin: 0 4px;
            border: 1px solid #d1d5db; border-radius: 4px;
            cursor: pointer; font-size: 14px;
        }
        .btn-print { background: #2563eb; color: #fff; border-color: #2563eb; }
        .btn-word { background: #16a34a; color: #fff; border-color: #16a34a; }
        .btn-close { background: #6b7280; color: #fff; border-color: #6b7280; }

        .oo-number { font-weight: bold; font-size: 13pt; margin-bottom: 28px; }
        .oo-number .num { text-decoration: underline; }

        .memo-head { margin-bottom: 6px; }
        .memo-row { display: flex; margin-bottom: 14px; }
        .memo-label { width: 78px; flex: 0 0 78px; font-weight: bold; }
        .memo-value { flex: 1; }
        .memo-name { font-weight: bold; text-transform: uppercase; }
        .memo-desig { font-style: italic; }

        .oo-rule { border: none; border-top: 2px solid #000; margin: 18px 0 22px; }

        .oo-body { text-align: justify; white-space: pre-line; margin-bottom: 26px; }
        .oo-closing { margin-bottom: 64px; }

        .conformed-label { margin-bottom: 48px; }
        .conformed-name { font-weight: bold; text-transform: uppercase; margin-bottom: 24px; }

        @media print {
            body { background: #fff; }
            .no-print { display: none; }
            .print-container { margin: 0; max-width: 100%; box-shadow: none; padding: 0.2in; }
        }
    </style>
</head>
<body>

<div class="no-print">
    <button class="btn-print" onclick="window.print()">Print</button>
    <button class="btn-word" onclick="window.location='/office-orders/{{ $order->id }}/word'">Download Word</button>
    <button class="btn-close" onclick="window.close()">Close</button>
</div>

<div class="print-container">
    {{-- Office Order number --}}
    <div class="oo-number">Office Order No. <span class="num">{{ $order->office_order_num ?? $order->id }}</span></div>

    {{-- Memo header: To / From / Subject / Date --}}
    <div class="memo-head">
        <div class="memo-row">
            <div class="memo-label">To</div>
            <div class="memo-value">
                @forelse ($employees as $emp)
                    <div style="margin-bottom:8px;">
                        <span class="memo-name">{{ $emp['name'] }}</span>
                        @if (!empty($emp['designation']))<br><span class="memo-desig">{{ $emp['designation'] }}</span>@endif
                    </div>
                @empty
                    <span class="memo-name">—</span>
                @endforelse
            </div>
        </div>
        <div class="memo-row">
            <div class="memo-label">From</div>
            <div class="memo-value">
                <span class="memo-name">{{ $issuer['name'] ?? '—' }}</span>
                @if (!empty($issuer['designation']))<br><span class="memo-desig">{{ $issuer['designation'] }}</span>@endif
            </div>
        </div>
        <div class="memo-row">
            <div class="memo-label">Subject</div>
            <div class="memo-value"><strong>{{ $order->subject ?? '—' }}</strong></div>
        </div>
        <div class="memo-row">
            <div class="memo-label">Date</div>
            <div class="memo-value">
                <strong>{{ !empty($order->issued_date) ? strtoupper(\Illuminate\Support\Carbon::parse($order->issued_date)->format('F d, Y')) : '—' }}</strong>
            </div>
        </div>
    </div>

    <hr class="oo-rule">

    {{-- Directive body --}}
    @if (!empty($order->details))
        <div class="oo-body">{{ $order->details }}</div>
    @endif

    {{-- Standard closing --}}
    <div class="oo-closing">For information and strict compliance.</div>

    {{-- Conformed by recipient(s) --}}
    <div class="conformed-label">Conformed:</div>
    @forelse ($employees as $emp)
        <div class="conformed-name">{{ $emp['name'] }}</div>
    @empty
        <div class="conformed-name">_______________________</div>
    @endforelse
</div>

</body>
</html>

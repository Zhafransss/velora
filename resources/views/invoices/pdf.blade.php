@php
    $fmt = fn ($n) => 'Rp ' . number_format((float) $n, 0, ',', '.');
    $pm = $invoice->payment_method_detail;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        @page { margin: 36px 40px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #1f2544; }
        table { width: 100%; border-collapse: collapse; }
        .head td { vertical-align: top; }
        .title { font-size: 26px; font-weight: bold; color: #4f46e5; text-align: right; letter-spacing: 2px; }
        .muted { color: #6b7280; }
        .label { font-size: 9px; text-transform: uppercase; color: #6b7280; letter-spacing: 1px; margin-bottom: 3px; }
        .items th { background: #1f2544; color: #fff; padding: 8px; text-align: left; font-size: 10px; }
        .items td { padding: 8px; border-bottom: 1px solid #e5e7eb; }
        .right { text-align: right; }
        .total td { padding: 10px 8px; font-weight: bold; font-size: 13px; background: #eef2ff; }
        .box { border: 1px solid #e5e7eb; padding: 10px 12px; }
        hr { border: 0; border-top: 2px solid #4f46e5; margin: 14px 0 18px; }
    </style>
</head>
<body>
    <table class="head">
        <tr>
            <td style="width:50%">
                <img src="{{ public_path('images/veloradigital-invoice.png') }}" style="height:80px" alt="Velora Digital">
            </td>
            <td>
                <div class="title">INVOICE</div>
                <div class="right muted" style="margin-top:6px">
                    No: <strong style="color:#1f2544">{{ $invoice->invoice_number }}</strong><br>
                    Tanggal: {{ $invoice->invoice_date->translatedFormat('d F Y') }}
                </div>
            </td>
        </tr>
    </table>

    <hr>

    <div class="label">Ditagihkan kepada</div>
    <div style="font-size:13px; font-weight:bold">{{ $invoice->customer_name }}</div>
    @if ($invoice->business_name)
        <div>{{ $invoice->business_name }}</div>
    @endif
    <div class="muted">{!! nl2br(e($invoice->address)) !!}</div>
    <div class="muted">WhatsApp: {{ $invoice->whatsapp }}</div>

    <table class="items" style="margin-top:22px">
        <thead>
            <tr>
                <th style="width:5%">#</th>
                <th>Deskripsi</th>
                <th class="right" style="width:8%">QTY</th>
                <th class="right" style="width:20%">Harga</th>
                <th class="right" style="width:20%">Jumlah</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($invoice->items as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $item['description'] }}</td>
                    <td class="right">{{ $item['qty'] }}</td>
                    <td class="right">{{ $fmt($item['price']) }}</td>
                    <td class="right">{{ $fmt($item['qty'] * $item['price']) }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr class="total">
                <td colspan="4" class="right">TOTAL</td>
                <td class="right">{{ $fmt($invoice->total) }}</td>
            </tr>
        </tfoot>
    </table>

    <table style="margin-top:24px">
        <tr>
            <td style="width:50%; vertical-align:top; padding-right:10px">
                <div class="label">Metode pembayaran</div>
                <div class="box">
                    <strong>{{ $pm['label'] }}</strong><br>
                    @if ($pm['account_number'])
                        No. Rekening: {{ $pm['account_number'] }}<br>
                        @if ($pm['account_name']) a.n. {{ $pm['account_name'] }} @endif
                    @else
                        <span class="muted">Nomor rekening akan diinformasikan.</span>
                    @endif
                </div>
            </td>
            <td style="vertical-align:top; padding-left:10px">
                <div class="label">Catatan</div>
                <div class="box">{!! $invoice->notes ? nl2br(e($invoice->notes)) : '<span class="muted">-</span>' !!}</div>
            </td>
        </tr>
    </table>

    <p class="muted" style="text-align:center; margin-top:40px; font-size:10px">
        Terima kasih atas kepercayaan Anda kepada Velora Digital.
    </p>
</body>
</html>

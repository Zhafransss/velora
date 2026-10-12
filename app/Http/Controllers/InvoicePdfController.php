<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class InvoicePdfController extends Controller
{
    public function __invoke(Request $request, Invoice $invoice)
    {
        $pdf = Pdf::loadView('invoices.pdf', ['invoice' => $invoice])->setPaper('a4');
        $filename = $invoice->invoice_number . '.pdf';

        return $request->boolean('download') ? $pdf->download($filename) : $pdf->stream($filename);
    }
}

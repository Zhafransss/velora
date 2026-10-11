<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreContactMessageRequest;
use App\Models\ContactMessage;

class ContactMessageController extends Controller
{

    public function index()
    {

        return view('pages.contact.index');

    }
    public function store(StoreContactMessageRequest $request)
    {
        $data = $request->validated();

        $contactMessage = ContactMessage::create([
            'name' => $data['name'],
            'phone' => $data['phone'],
            'email' => $data['email'] ?? null,
            'type' => $data['type'],
            'message' => $data['message'],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        $adminNumber = preg_replace('/\D+/', '', config('services.whatsapp.admin_number'));

        $waText = <<<TEXT
Halo Velora Digital, saya baru saja mengirim form kontak.

Nama: {$contactMessage->name}
WhatsApp: {$contactMessage->phone}
Email: {$contactMessage->email}
Jenis Website: {$contactMessage->type}

Pesan:
{$contactMessage->message}
TEXT;

        $waUrl = 'https://wa.me/' . $adminNumber . '?text=' . urlencode($waText);

        return redirect()->away($waUrl);
    }


}
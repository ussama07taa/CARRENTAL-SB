<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class WebsiteRequestReceived extends Mailable
{
    public function __construct(public string $reference, public string $language, public string $trackingUrl) {}

    public function envelope(): Envelope
    {
        $subject = ['fr' => 'Demande reçue', 'en' => 'Request received', 'es' => 'Solicitud recibida', 'ar' => 'تم استلام طلبك'][$this->language];

        return new Envelope(subject: 'Ben Sbih Rental Car · '.$subject.' · '.$this->reference);
    }

    public function content(): Content
    {
        $copy = [
            'fr' => ['Nous avons reçu votre demande.', 'Numéro de réservation', 'Votre voiture n’est pas encore confirmée. L’agence vous contactera par téléphone ou e-mail pour confirmer votre réservation. Aucun montant n’est prélevé maintenant.', 'Voir ou gérer ma réservation', 'Ce lien est privé. Ne le partagez pas.'],
            'en' => ['We have received your request.', 'Booking reference', 'Your car is not confirmed yet. The agency will call or email you to confirm your booking. Nothing is charged now.', 'View or manage my booking', 'This link is private. Do not share it.'],
            'es' => ['Hemos recibido tu solicitud.', 'Referencia de reserva', 'Tu coche aún no está confirmado. La agencia te llamará o te enviará un correo para confirmar la reserva. No se cobra nada ahora.', 'Ver o gestionar mi reserva', 'Este enlace es privado. No lo compartas.'],
            'ar' => ['توصلنا بطلبك.', 'رقم الحجز', 'لم يتم تأكيد سيارتك بعد. ستتواصل معك الوكالة هاتفياً أو عبر البريد الإلكتروني لتأكيد الحجز. لن يُقتطع أي مبلغ الآن.', 'عرض أو تدبير حجزي', 'هذا الرابط خاص. لا تشاركه.'],
        ][$this->language];

        return new Content(view: 'emails.website-request', with: ['copy' => $copy]);
    }
}

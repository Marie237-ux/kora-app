<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Notifications\Messages\MailMessage;

final class VerifyAccountEmail extends VerifyEmail
{
    private function expiryMinutes(): int
    {
        return max(1, min(1440, (int) config('auth.verification.expire', 60)));
    }

    protected function verificationUrl($notifiable)
    {
        // Use the configured API origin, never the request's untrusted Host header.
        $urls = clone app('url');
        $urls->forceRootUrl(config('app.url'));

        return $urls->temporarySignedRoute('verification.verify', now()->addMinutes($this->expiryMinutes()), [
            'id' => $notifiable->getKey(),
            'hash' => sha1($notifiable->getEmailForVerification()),
        ]);
    }

    public function toMail($notifiable)
    {
        $locale = $notifiable->locale === 'en' ? 'en' : 'fr';
        $data = ['locale' => $locale, 'url' => $this->verificationUrl($notifiable)];
        foreach (['subject', 'greeting', 'intro', 'action', 'signature'] as $key) {
            $data[$key] = __('verification.'.$key, [], $locale);
        }
        $data['expiry'] = __('verification.expiry', ['minutes' => $this->expiryMinutes()], $locale);

        return (new MailMessage)
            ->subject($data['subject'])
            ->view(['html' => 'emails.verify-account', 'text' => 'emails.verify-account-text'], $data);
    }
}

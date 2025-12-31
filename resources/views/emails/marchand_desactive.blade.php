@component('mail::layout')
    @slot('header')
        @component('mail::header', ['url' => config('app.url')])
            <div style="text-align: center; background: linear-gradient(135deg, #ff8c00, #ff6b00); padding: 30px 20px; color: white; border-radius: 8px 8px 0 0;">
                <div style="font-size: 28px; font-weight: 700; margin-bottom: 10px;">
                    TropDouxRecup
                </div>
                <h1 style="font-size: 24px; font-weight: 600; margin: 0;">
                    Compte désactivé ⚠️
                </h1>
            </div>
        @endcomponent
    @endslot

# Bonjour {{ $marchand->nom_marchand }},

Nous vous informons que **votre compte marchand a été désactivé**.

<div style="text-align: center; margin: 30px 0; padding: 20px; background-color: #fff9f0; border-radius: 8px; border-left: 4px solid #ff8c00;">
    <p style="font-size: 16px; color: #666; margin-bottom: 10px;">
        L’accès à votre espace marchand est temporairement suspendu.
    </p>
    <p style="font-size: 16px; font-weight: bold; color: #666;">
        Pour plus d’informations, veuillez contacter notre support.
    </p>
</div>

⚠️ **Aucune action n’est requise de votre part** tant que le problème n’est pas résolu.

@slot('footer')
    @component('mail::footer')
        <div style="background-color: #f5f5f5; padding: 20px; text-align: center; border-top: 1px solid #eee; color: #777; font-size: 14px;">
            <p style="margin: 5px 0;">
                © {{ date('Y') }} TropDouxRecup. Tous droits réservés.
            </p>
            <p style="margin: 5px 0;">
                Cet email a été envoyé automatiquement, merci de ne pas y répondre.
            </p>
        </div>
    @endcomponent
@endslot
@endcomponent

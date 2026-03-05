@component('mail::message')

{{-- Header --}}
<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#ff8c00;padding:20px;text-align:center;border-radius:8px 8px 0 0;">
    <tr>
        <td style="color:white;font-size:22px;font-weight:bold;">
            TropDouxRecup
        </td>
    </tr>
    <tr>
        <td style="color:white;font-size:18px;padding-top:5px;">
            Nouvel abonnement souscrit
        </td>
    </tr>
</table>

<br>

Bonjour chère admin,

Un marchand vient de souscrire à un nouvel abonnement.

<br><br>

{{-- Informations du marchand --}}
<table width="100%" cellpadding="10" cellspacing="0" style="background-color:#fff4e6;border:1px solid #ffd8b0;border-radius:8px;">
    <tr>
        <td><strong>Nom :</strong></td>
        <td>{{ $marchand->nom_marchand }}</td>
    </tr>
    <tr>
        <td><strong>Email :</strong></td>
        <td>{{ $marchand->email_marchand }}</td>
    </tr>
    <tr>
        <td><strong>Téléphone :</strong></td>
        <td>{{ $marchand->tel_marchand }}</td>
    </tr>
</table>

<br>

{{-- Détails de l'abonnement --}}
<table width="100%" cellpadding="15" cellspacing="0" style="background-color:#fff9e6;border:1px solid #ffcc80;border-radius:8px;">
    <tr>
        <td style="font-weight:bold; color:#e65100;">📦 Détails abonnement</td>
    </tr>
    <tr>
        <td>
            <ul style="margin:5px 0;padding-left:20px;color:#333;font-size:14px;">
                <li><strong>Type :</strong> {{ $abonnement->type_abonnement }}</li>
                <li><strong>Montant :</strong> {{ number_format($paiement->prix, 0, ',', ' ') }} FCFA</li>
                <li><strong>Durée :</strong> {{ $abonnement->duree }}</li>
                @if($marchand->fin_abonnement)
                    <li><strong>Valide jusqu’au :</strong> {{ $marchand->fin_abonnement->format('d/m/Y') }}</li>
                @else
                    <li><strong>Valide :</strong> Illimité</li>
                @endif
            </ul>
        </td>
    </tr>
</table>

<br>

<br>

Merci pour votre gestion.

@endcomponent
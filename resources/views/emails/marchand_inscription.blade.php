@component('mail::message')

<table width="100%" cellpadding="0" cellspacing="0" style="background-color:#ff8c00;padding:20px;text-align:center;">
    <tr>
        <td style="color:white;font-size:22px;font-weight:bold;">
        TropDouxRecup
        </td>
    </tr>
    <tr>
        <td style="color:white;font-size:18px;padding-top:5px;">
            Nouvelle inscription marchand
        </td>
    </tr>
</table>

<br>

Bonjour chère admin,

Un nouveau marchand vient de s'inscrire sur la plateforme.

<br><br>

<table width="100%" cellpadding="10" cellspacing="0" style="background-color:#fff4e6;border:1px solid #ffd8b0;">
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

@if($type === 'active')
<table width="100%" cellpadding="15" cellspacing="0" style="background-color:#e8f5e9;border-left:5px solid #4caf50;">
    <tr>
        <td style="color:#2e7d32;font-weight:bold;text-align:center;">
            Compte activé automatiquement
        </td>
    </tr>
</table>
@else
<table width="100%" cellpadding="15" cellspacing="0" style="background-color:#fff3e0;border-left:5px solid #ff8c00;">
    <tr>
        <td style="color:#e65100;font-weight:bold;text-align:center;">
            Compte en attente d'activation
        </td>
    </tr>
</table>
@endif

<br>

Merci pour votre gestion.

@endcomponent
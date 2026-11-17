@extends('web.layout')
@section('content')
{{-- Conditions d'utilisation et confidentialité : texte simple, à faire relire avant le lancement public --}}
<article class="card legal">
    @if ($page === 'conditions')
        <h1>Conditions d'utilisation</h1>
        <p class="faint small">Dernière mise à jour : {{ $updated }}</p>
        <h2>Sosa en bref</h2>
        <p>Sosa permet de lancer des défis, des duels, des concours et des tournois, d'y participer et de voter. En créant un compte, tu acceptes ces règles.</p>
        <h2>Ton compte</h2>
        <p>Un compte par personne, avec ton vrai numéro de téléphone. Tu es responsable de ce qui est publié depuis ton compte. Les moins de 13 ans ne peuvent pas s'inscrire.</p>
        <h2>Ce que tu publies</h2>
        <p>Tu publies seulement ce qui t'appartient (ta voix, ton image, ta musique ou celle que tu as le droit d'utiliser). Sont interdits : la nudité, la violence, la haine, le harcèlement, les arnaques, et l'usage de l'image d'une autre personne sans son accord. Tu nous autorises à afficher et partager tes publications sur Sosa et dans ses aperçus de partage.</p>
        <h2>Votes et triche</h2>
        <p>Un vote par personne, sauf votes payants prévus par l'organisateur. Les comptes multiples, les votes achetés hors de Sosa et les fausses positions sont interdits : les votes suspects sont annulés et les comptes peuvent être suspendus.</p>
        <h2>Compétitions et récompenses</h2>
        <p>Chaque organisateur fixe ses règles, son jury et ses récompenses, et il est responsable de les remettre. Sosa affiche les résultats calculés selon ces règles.</p>
        <h2>Coins</h2>
        <p>Les coins servent à voter (votes payants) et à offrir des cadeaux sur Sosa. Ils ne sont pas une monnaie. Les coins achetés ne sont pas remboursables, sauf erreur de notre part. Seuls les coins gagnés peuvent être changés en argent, quand cette option est ouverte.</p>
        <h2>Modération</h2>
        <p>Tout le monde peut signaler un contenu. Nous pouvons retirer un contenu ou suspendre un compte qui ne respecte pas ces règles.</p>
        <h2>Nous contacter</h2>
        <p>Pour toute question : {{ config('mail.from.address') ?: 'l\'équipe Sosa' }}.</p>
    @else
        <h1>Confidentialité</h1>
        <p class="faint small">Dernière mise à jour : {{ $updated }}</p>
        <h2>Ce que nous gardons</h2>
        <ul>
            <li>Ton numéro de téléphone, ton nom, ta date de naissance, ta photo et ce que tu publies.</li>
            <li>Ta ville et tes centres d'intérêt, si tu les indiques.</li>
            <li>Tes votes, tes abonnements et tes Espaces.</li>
            <li>Un identifiant de l'appareil et l'adresse IP, seulement pour bloquer la triche aux votes.</li>
        </ul>
        <h2>Ta position</h2>
        <p>Nous la demandons uniquement quand une compétition est réservée à une ville, ou quand tu touches « Me localiser ». Nous en tirons le nom de ta ville et nous ne gardons pas tes coordonnées. Pour trouver la ville, la position peut être envoyée au service OpenStreetMap.</p>
        <h2>Certification</h2>
        <p>Les pièces envoyées pour le badge certifié ne sont vues que par l'équipe Sosa et sont supprimées {{ config('certification.keep_documents_days') }} jours après la décision.</p>
        <h2>Ce que les autres voient</h2>
        <p>Ton profil, tes publications, tes participations et tes résultats sont publics. Ton numéro de téléphone ne l'est jamais.</p>
        <h2>Avec qui nous partageons</h2>
        <p>Personne ne nous achète tes données. Nous utilisons des prestataires pour envoyer les SMS et messages WhatsApp, stocker les médias et recevoir les paiements, seulement pour faire fonctionner Sosa.</p>
        <h2>Tes droits</h2>
        <p>Tu peux modifier ton profil à tout moment, et nous demander une copie ou la suppression de ton compte : {{ config('mail.from.address') ?: 'écris à l\'équipe Sosa' }}.</p>
    @endif
</article>
@endsection

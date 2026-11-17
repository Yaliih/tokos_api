<form class="pp-form" method="post" action="/c/{{ $c['slug'] }}/remise" enctype="multipart/form-data">@csrf
    <label class="pp-file">@icon('video', 'ico-sm')<span data-file-label>Choisir la vidéo ou la photo</span>
        <input type="file" name="files[]" accept="video/*,image/*" multiple required data-file-input></label>
    <textarea name="text" rows="2" maxlength="500" placeholder="Un mot : qui a gagné quoi, chez quel sponsor (tape @ pour citer une personne ou un Espace)" data-mention></textarea>
    <button class="btn">@icon('upload', 'ico-sm')Publier la remise du prix</button>
</form>

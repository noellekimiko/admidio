<?php
/**
 ***********************************************************************************************
 * Show a list of all photo albums
 *
 * @copyright 2004-2019 The Admidio Team
 * @see https://www.admidio.org/
 * @license https://www.gnu.org/licenses/gpl-2.0.html GNU General Public License v2.0 only
 ***********************************************************************************************
 */

/******************************************************************************
 * Parameters:
 *
 * pho_id    : Id of album which photos should be shown
 * headline  : Headline of the module that will be displayed
 *             (Default) PHO_PHOTO_ALBUMS
 * start_thumbnail : Number of the thumbnail which is the first that should be shown
 * start     : Position of query recordset where the visual output should start
 *
 *****************************************************************************/

require_once(__DIR__ . '/../../system/common.php');

// check if the module is enabled and disallow access if it's disabled
if ((int) $gSettingsManager->get('enable_photo_module') === 0)
{
    $gMessage->show($gL10n->get('SYS_MODULE_DISABLED'));
    // => EXIT
}
elseif ((int) $gSettingsManager->get('enable_photo_module') === 2)
{
    // nur eingeloggte Benutzer duerfen auf das Modul zugreifen
    require(__DIR__ . '/../../system/login_valid.php');
}

// Initialize and check the parameters
$getPhotoId        = admFuncVariableIsValid($_GET, 'pho_id',          'int');
$getHeadline       = admFuncVariableIsValid($_GET, 'headline',        'string', array('defaultValue' => $gL10n->get('PHO_PHOTO_ALBUMS')));
$getStart          = admFuncVariableIsValid($_GET, 'start',           'int');
$getStartThumbnail = admFuncVariableIsValid($_GET, 'start_thumbnail', 'int', array('defaultValue' => 1));
$getPhotoNr        = admFuncVariableIsValid($_GET, 'photo_nr',        'int');

unset($_SESSION['photo_album_request'], $_SESSION['ecard_request']);

// Fotoalbums-Objekt erzeugen oder aus Session lesen
if (isset($_SESSION['photo_album']) && (int) $_SESSION['photo_album']->getValue('pho_id') === $getPhotoId)
{
    $photoAlbum =& $_SESSION['photo_album'];
}
else
{
    // einlesen des Albums falls noch nicht in Session gespeichert
    $photoAlbum = new TablePhotos($gDb);
    if ($getPhotoId > 0)
    {
        $photoAlbum->readDataById($getPhotoId);
    }

    $_SESSION['photo_album'] = $photoAlbum;
}

// set headline of module
if ($getPhotoId > 0)
{
    // check if the current user could view this photo album
    if(!$photoAlbum->isVisible())
    {
        $gMessage->show($gL10n->get('SYS_NO_RIGHTS'));
        // => EXIT
    }

    $headline = $photoAlbum->getValue('pho_name');
}
else
{
    $headline = $getHeadline;
}

// Wurde keine Album uebergeben kann das Navigationsstack zurueckgesetzt werden
if ($getPhotoId === 0)
{
    $gNavigation->clear();
}

// URL auf Navigationstack ablegen
$gNavigation->addUrl(CURRENT_URL, $headline);

// create html page object
$page = new HtmlPage($headline);
$page->enableModal();

// add rss feed to photos
if ($gSettingsManager->getBool('enable_rss'))
{
    $page->addRssFile(
        SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/rss_photos.php', array('headline' => $getHeadline)),
        $gL10n->get('SYS_RSS_FEED_FOR_VAR', array($gCurrentOrganization->getValue('org_longname'). ' - '.$getHeadline))
    );
}

if ($photoAlbum->isEditable())
{
    $page->addJavascript('
        /**
         * rotate image
         * @param {int}    img
         * @param {string} direction
         */
        function imgrotate(img, direction) {
            $.get("'.ADMIDIO_URL.FOLDER_MODULES.'/photos/photo_function.php", {pho_id: '.$getPhotoId.', photo_nr: img, job: "rotate", direction: direction}, function(data) {
                // Anhängen der Zufallszahl ist nötig um den Browsercache zu überlisten
                $("#img_" + img).attr("src", "'.SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photo_show.php', array('pho_id' => $getPhotoId, 'thumb' => 1)).'&photo_nr=" + img + "&rand=" + Math.random());
                return false;
            });
        }'
    );
}

// integrate bootstrap ekko lightbox addon
if ((int) $gSettingsManager->get('photo_show_mode') === 1)
{
    $page->addCssFile(ADMIDIO_URL . FOLDER_LIBS_CLIENT . '/lightbox/dist/ekko-lightbox.css');
    $page->addJavascriptFile(ADMIDIO_URL . FOLDER_LIBS_CLIENT . '/lightbox/dist/ekko-lightbox.js');

    $page->addJavascript('
        $(document).delegate("*[data-toggle=\"lightbox\"]", "click", function(event) {
            event.preventDefault();
            $(this).ekkoLightbox();
        });',
        true
    );
}

$page->addJavascript('
    $("body").on("hidden.bs.modal", ".modal", function() {
        $(this).removeData("bs.modal");
        location.reload();
    });
    $("#menu_item_upload_photo").attr("data-toggle", "modal");
    $("#menu_item_upload_photo").attr("data-target", "#admidio_modal");
    $(".admidio-btn-album-upload").click(function(event) {
        $.get("'.SecurityUtils::encodeUrl(ADMIDIO_URL.'/adm_program/system/file_upload.php', array('module' => 'photos')).'&id=" + $(this).attr("data-pho-id"), function(response) {
            $(".modal-content").html(response);
            $("#admidio_modal").modal();
        });
    });',
    true
);

// if a photo number was committed then simulate a left mouse click
if ($getPhotoNr > 0)
{
    $page->addJavascript('$("#img_'.$getPhotoNr.'").trigger("click");', true);
}

// get module menu
$photosMenu = $page->getMenu();

if ($photoAlbum->getValue('pho_id') > 0)
{
    $photosMenu->addItem('menu_item_back', $gNavigation->getPreviousUrl(), $gL10n->get('SYS_BACK'), 'fa-arrow-circle-left');
}

if ($gCurrentUser->editPhotoRight())
{
    // show link to create new album
    $photosMenu->addItem(
        'menu_item_new_album', SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photo_album_new.php', array('mode' => 'new', 'pho_id' => $getPhotoId)),
        $gL10n->get('PHO_CREATE_ALBUM'), 'fa-plus-circle'
    );

    if ($getPhotoId > 0)
    {
        // show link to upload photos
        $photosMenu->addItem(
            'menu_item_upload_photo', SecurityUtils::encodeUrl(ADMIDIO_URL.'/adm_program/system/file_upload.php', array('module' => 'photos', 'id' => $getPhotoId)),
            $gL10n->get('PHO_UPLOAD_PHOTOS'), 'fa-upload'
        );
    }
}

// show link to download photos if enabled
if ($gSettingsManager->getBool('photo_download_enabled') && $photoAlbum->getValue('pho_quantity') > 0)
{
    // show link to download photos
    $photosMenu->addItem(
        'menu_item_download_photos', SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photo_download.php', array('pho_id' => $getPhotoId)),
        $gL10n->get('SYS_DOWNLOAD_ALBUM'), 'fa-download'
    );
}

if ($gCurrentUser->isAdministrator())
{
    // show link to system preferences of photos
    $photosMenu->addItem(
        'menu_item_preferences_photos', SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/preferences/preferences.php', array('show_option' => 'photos')),
        $gL10n->get('SYS_MODULE_PREFERENCES'), 'fa-cog', 'right'
    );
}

// Breadcrump bauen
$navilink = '';
$phoParentId = (int) $photoAlbum->getValue('pho_pho_id_parent');
$photoAlbumParent = new TablePhotos($gDb);

while ($phoParentId > 0)
{
    // get parent photo album
    $photoAlbumParent->readDataById($phoParentId);

    // create link to parent photo album
    $navilink = '<li class="breadcrumb-item"><a href="'.SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photos.php', array('pho_id' => (int) $photoAlbumParent->getValue('pho_id'))).'">'.
        $photoAlbumParent->getValue('pho_name') . '</a></li>' . $navilink;

    $phoParentId = (int) $photoAlbumParent->getValue('pho_pho_id_parent');
}

if ($getPhotoId > 0)
{
    // show additional album information
    $datePeriod = $photoAlbum->getValue('pho_begin', $gSettingsManager->getString('system_date'));

    if ($photoAlbum->getValue('pho_end') !== $photoAlbum->getValue('pho_begin') && strlen($photoAlbum->getValue('pho_end')) > 0)
    {
        $datePeriod .= ' '.$gL10n->get('SYS_DATE_TO').' '.$photoAlbum->getValue('pho_end', $gSettingsManager->getString('system_date'));
    }

    $page->addHtml('
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item">
                <i class="fas fa-image"></i>
                <a href="'.ADMIDIO_URL.FOLDER_MODULES.'/photos/photos.php">'.$gL10n->get('PHO_PHOTO_ALBUMS').'</a>
            </li>'.
            $navilink.'
            <li class="breadcrumb-item active" aria-current="page">'.$photoAlbum->getValue('pho_name').'</li>
        </ol>
    </nav>

    <div class="card admidio-album-informations">
        <div class="card-body">
            <h5 class="card-title">' . $datePeriod . '</h5>');

            if (strlen($photoAlbum->getValue('pho_description')) > 0)
            {
                $page->addHtml('<p class="card-text">' . $photoAlbum->getValue('pho_description') . '</p>');
            }

            $page->addHtml('
            <p class="card-text">' . $photoAlbum->countImages() . ' ' . $gL10n->get('PHO_PHOTOGRAPHER') . ' ' . $photoAlbum->getValue('pho_photographers') . '</p>
        </div>
    </div>');
}

/*************************THUMBNAILS**********************************/
// Nur wenn uebergebenes Album Bilder enthaelt
if ($photoAlbum->getValue('pho_quantity') > 0)
{
    $photoThumbnailTable = '';
    $firstPhotoNr        = 1;
    $lastPhotoNr         = $gSettingsManager->getInt('photo_thumbs_page');

    // Wenn Bild übergeben wurde richtige Albenseite öffnen
    if ($getPhotoNr > 0)
    {
        $firstPhotoNr = (round(($getPhotoNr - 1) / $gSettingsManager->getInt('photo_thumbs_page')) * $gSettingsManager->getInt('photo_thumbs_page')) + 1;
        $lastPhotoNr  = $firstPhotoNr + $gSettingsManager->getInt('photo_thumbs_page') - 1;
    }

    // create thumbnail container
    $page->addHtml('<div class="row admidio-album-container mb-5">');

    for ($actThumbnail = $firstPhotoNr; $actThumbnail <= $lastPhotoNr && $actThumbnail <= $photoAlbum->getValue('pho_quantity'); ++$actThumbnail)
    {
        if ($actThumbnail <= $photoAlbum->getValue('pho_quantity'))
        {
            $photoThumbnailTable .= '<div class="col-sm-6 col-md-3 admidio-album-thumbnail text-center" id="div_image_'.$actThumbnail.'">';

                // Popup window
                if ((int) $gSettingsManager->get('photo_show_mode') === 0)
                {
                    $photoThumbnailTable .= '
                        <img class="img-thumbnail" id="img_'.$actThumbnail.'" style="cursor: pointer"
                            onclick="window.open(\''.SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photo_presenter.php', array('photo_nr' => $actThumbnail, 'pho_id' => $getPhotoId)).'\',\'msg\', \'height='.($gSettingsManager->getInt('photo_show_height') + 210).', width='.($gSettingsManager->getInt('photo_show_width')+70).',left=162,top=5\')"
                            src="'.SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photo_show.php', array('pho_id' => $getPhotoId, 'photo_nr' => $actThumbnail, 'thumb' => 1)).'" alt="'.$actThumbnail.'" />';
                }

                // Bootstrap modal with lightbox
                elseif ((int) $gSettingsManager->get('photo_show_mode') === 1)
                {
                    $photoThumbnailTable .= '
                        <a data-gallery="admidio-gallery" data-type="image" data-parent=".admidio-album-container" data-toggle="lightbox" data-title="'.$headline.'"
                            href="'.SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photo_show.php', array('pho_id' => $getPhotoId, 'photo_nr' => $actThumbnail, 'max_width' => $gSettingsManager->getInt('photo_show_width'), 'max_height' => $gSettingsManager->getInt('photo_show_height'))).'"><img
                            class="img-thumbnail" id="img_'.$actThumbnail.'" src="'.SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photo_show.php', array('pho_id' => $getPhotoId, 'photo_nr' => $actThumbnail, 'thumb' => 1)).'" alt="'.$actThumbnail.'" /></a>';
                }

                // Same window
                elseif ((int) $gSettingsManager->get('photo_show_mode') === 2)
                {
                    $photoThumbnailTable .= '
                        <a href="'.SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photo_presenter.php', array('photo_nr' => $actThumbnail, 'pho_id' => $getPhotoId)).'"><img
                            class="img-thumbnail" id="img_'.$actThumbnail.'" src="'.SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photo_show.php', array('pho_id' => $getPhotoId, 'photo_nr' => $actThumbnail, 'thumb' => 1)).'" />
                        </a>';
                }

                if ($gCurrentUser->editPhotoRight() || ($gValidLogin && $gSettingsManager->getBool('enable_ecard_module')) || $gSettingsManager->getBool('photo_download_enabled'))
                {
                    $photoThumbnailTable .= '<div class="text-center" id="image_preferences_'.$actThumbnail.'">';
                }


                if ($gValidLogin && $gSettingsManager->getBool('enable_ecard_module'))
                {
                    $photoThumbnailTable .= '
                        <a class="admidio-icon-link" href="'.SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/ecards/ecards.php', array('photo_nr' => $actThumbnail, 'pho_id' => $getPhotoId, 'show_page' => $getPhotoNr)).'">
                            <i class="fas fa-envelope" data-toggle="tooltip" title="'.$gL10n->get('PHO_PHOTO_SEND_ECARD').'"></i></a>';
                }

                if ($gSettingsManager->getBool('photo_download_enabled'))
                {
                    // show link to download photo
                    $photoThumbnailTable .= '
                        <a class="admidio-icon-link" href="'.SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photo_download.php', array('pho_id' => $getPhotoId, 'photo_nr' => $actThumbnail)).'">
                            <i class="fas fa-download" data-toggle="tooltip" title="'.$gL10n->get('SYS_DOWNLOAD_PHOTO').'"></i></a>';
                }

                // buttons for moderation
                if ($gCurrentUser->editPhotoRight())
                {
                    $photoThumbnailTable .= '
                        <a class="admidio-icon-link" href="javascript:void(0)" onclick="return imgrotate('.$actThumbnail.', \'right\')">
                            <i class="fas fa-redo-alt" data-toggle="tooltip" title="'.$gL10n->get('PHO_PHOTO_ROTATE_RIGHT').'"></i></a>
                        <a class="admidio-icon-link"  href="javascript:void(0)" onclick="return imgrotate('.$actThumbnail.', \'left\')">
                            <i class="fas fa-undo-alt" data-toggle="tooltip" title="'.$gL10n->get('PHO_PHOTO_ROTATE_LEFT').'"></i></a>
                        <a class="admidio-icon-link" data-toggle="modal" data-target="#admidio_modal"
                            href="'.SecurityUtils::encodeUrl(ADMIDIO_URL.'/adm_program/system/popup_message.php', array('type' => 'pho', 'element_id' => 'div_image_'.$actThumbnail,
                            'database_id' => $actThumbnail, 'database_id_2' => $getPhotoId)).'">
                            <i class="fas fa-trash-alt" data-toggle="tooltip" title="'.$gL10n->get('SYS_DELETE').'"></i></a>';
                }

                if ($gCurrentUser->editPhotoRight() || ($gValidLogin && $gSettingsManager->getBool('enable_ecard_module')) || $gSettingsManager->getBool('photo_download_enabled'))
                {
                    $photoThumbnailTable .= '</div>';
                }
            $photoThumbnailTable .= '</div>';
        }
    }

    // the lightbox should be able to go through the whole album, therefore we must
    // integrate links to the photos of the album pages to this page and container but hidden
    if ((int) $gSettingsManager->get('photo_show_mode') === 1)
    {
        $photoThumbnailTableShown = false;

        for ($hiddenPhotoNr = 1; $hiddenPhotoNr <= $photoAlbum->getValue('pho_quantity'); ++$hiddenPhotoNr)
        {
            if ($hiddenPhotoNr >= $firstPhotoNr && $hiddenPhotoNr <= $actThumbnail)
            {
                if (!$photoThumbnailTableShown)
                {
                    $page->addHtml($photoThumbnailTable);
                    $photoThumbnailTableShown = true;
                }
            }
            else
            {
                $page->addHtml('
                    <a class="hidden" data-gallery="admidio-gallery" data-type="image" data-toggle="lightbox" data-title="'.$headline.'"
                        href="'.SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photo_show.php', array('pho_id' => $getPhotoId, 'photo_nr' => $hiddenPhotoNr, 'max_width' => $gSettingsManager->getInt('photo_show_width'), 'max_height' => $gSettingsManager->getInt('photo_show_height'))).'">&nbsp;</a>
                ');
            }
        }
        $page->addHtml('</div>');   // close album-container
    }
    else
    {
        // show photos if lightbox is not used
        $photoThumbnailTable .= '</div>';   // close album-container
        $page->addHtml($photoThumbnailTable);
    }

    // show information about user who creates the recordset and changed it
    $page->addHtml(admFuncShowCreateChangeInfoById(
        (int) $photoAlbum->getValue('pho_usr_id_create'), $photoAlbum->getValue('pho_timestamp_create'),
        (int) $photoAlbum->getValue('pho_usr_id_change'), $photoAlbum->getValue('pho_timestamp_change')
    ));

    // show page navigations through thumbnails
    $page->addHtml(admFuncGeneratePagination(
        SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photos.php', array('pho_id' => (int) $photoAlbum->getValue('pho_id'))),
        (int) $photoAlbum->getValue('pho_quantity'),
        $gSettingsManager->getInt('photo_thumbs_page'),
        $getPhotoNr,
        true,
        'photo_nr'
    ));

}
/************************ Album list *************************************/

// show all albums of the current level
$sql = 'SELECT *
          FROM '.TBL_PHOTOS.'
         WHERE pho_org_id = ? -- $gCurrentOrganization->getValue(\'org_id\')';
$queryParams = array((int) $gCurrentOrganization->getValue('org_id'));
if ($getPhotoId === 0)
{
    $sql .= '
        AND (pho_pho_id_parent IS NULL) ';
}
if ($getPhotoId > 0)
{
    $sql .= '
        AND pho_pho_id_parent = ? -- $getPhotoId';
    $queryParams[] = $getPhotoId;
}
if (!$gCurrentUser->editPhotoRight())
{
    $sql .= '
        AND pho_locked = 0 ';
}

$sql .= '
    ORDER BY pho_begin DESC';

$albumStatement = $gDb->queryPrepared($sql, $queryParams);
$albumList      = $albumStatement->fetchAll();

// Gesamtzahl der auszugebenden Alben
$albumsCount = $albumStatement->rowCount();

// falls zum aktuellen Album Fotos und Unteralben existieren,
// dann einen Trennstrich zeichnen
if ($albumsCount > 0 && $photoAlbum->getValue('pho_quantity') > 0)
{
    $page->addHtml('<hr />');
}

$childPhotoAlbum = new TablePhotos($gDb);

$page->addHtml('<div class="row">');

for ($x = $getStart; $x <= $getStart + $gSettingsManager->getInt('photo_albums_per_page') - 1 && $x < $albumsCount; ++$x)
{
    $htmlLock = '';
    // Daten in ein Photo-Objekt uebertragen
    $childPhotoAlbum->clear();
    $childPhotoAlbum->setArray($albumList[$x]);

    // folder of the album
    $albumFolder = ADMIDIO_PATH . FOLDER_DATA . '/photos/' . $childPhotoAlbum->getValue('pho_begin', 'Y-m-d') . '_' . (int) $childPhotoAlbum->getValue('pho_id');

    // show album if album is not locked or it has child albums or the user has the photo module edit right
    if ((is_dir($albumFolder) && $childPhotoAlbum->getValue('pho_locked') == 0)
    || $childPhotoAlbum->hasChildAlbums() || $gCurrentUser->editPhotoRight())
    {
        // Zufallsbild fuer die Vorschau ermitteln
        $shuffleImage = $childPhotoAlbum->shuffleImage();

        // Album angaben
        if (is_dir($albumFolder) || $childPhotoAlbum->hasChildAlbums())
        {
            $albumTitle = '<a href="'.SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photos.php', array('pho_id' => (int) $childPhotoAlbum->getValue('pho_id'))).'">'.$childPhotoAlbum->getValue('pho_name').'</a>';
        }
        else
        {
            $albumTitle = $childPhotoAlbum->getValue('pho_name');
        }

        $albumDate = $childPhotoAlbum->getValue('pho_begin', $gSettingsManager->getString('system_date'));
        if ($childPhotoAlbum->getValue('pho_end') !== $childPhotoAlbum->getValue('pho_begin'))
        {
            $albumDate .= ' '.$gL10n->get('SYS_DATE_TO').' '.$childPhotoAlbum->getValue('pho_end', $gSettingsManager->getString('system_date'));
        }

        $page->addHtml('
            <div class="col-sm-6 admidio-album-card" id="panel_pho_'.(int) $childPhotoAlbum->getValue('pho_id').'">
                <div class="card">
                    <a href="'.SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photos.php', array('pho_id' => (int) $childPhotoAlbum->getValue('pho_id'))).'"><img
                        class="card-img-top" src="'.SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photo_show.php', array('pho_id' => $shuffleImage['shuffle_pho_id'], 'photo_nr' => $shuffleImage['shuffle_img_nr'], 'thumb' => 1)).'" alt="'.$gL10n->get('PHO_PHOTOS').'" /></a>
                    <div class="card-body">
                        <h5 class="card-title">'.$albumTitle);

                            // if user has admin rights for photo module then show some functions
                            if ($gCurrentUser->editPhotoRight())
                            {
                                if ($childPhotoAlbum->getValue('pho_locked') != 1)
                                {
                                    $htmlLock = '<a class="admidio-icon-link" href="'.SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photo_album_function.php', array('pho_id' => (int) $childPhotoAlbum->getValue('pho_id'), 'mode' => 'lock')).'">
                                        <i class="fas fa-lock" data-toggle="tooltip" title="'.$gL10n->get('PHO_ALBUM_LOCK').'"></i></a>';
                                }
                    
                                $page->addHtml('<div class="float-right">
                                    <a class="admidio-icon-link" href="'.SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photo_album_new.php', array('pho_id' => (int) $childPhotoAlbum->getValue('pho_id'), 'mode' => 'change')).'">
                                        <i class="fas fa-edit" data-toggle="tooltip" title="'.$gL10n->get('SYS_EDIT').'"></i></a>
                                    ' . $htmlLock . '
                                    <a class="admidio-icon-link" data-toggle="modal" data-target="#admidio_modal"
                                        href="'.SecurityUtils::encodeUrl(ADMIDIO_URL.'/adm_program/system/popup_message.php', array('type' => 'pho_album', 'element_id' => 'panel_pho_'.(int) $childPhotoAlbum->getValue('pho_id'),
                                        'name' => $childPhotoAlbum->getValue('pho_name'), 'database_id' => (int) $childPhotoAlbum->getValue('pho_id'))).'">
                                        <i class="fas fa-trash-alt" data-toggle="tooltip" title="'.$gL10n->get('SYS_DELETE').'"></i></a>
                                    </div>
                                ');
                            }


                        $page->addHtml('</h5>

                        <p class="card-text">' . $albumDate . '</p>');

                        if (strlen($childPhotoAlbum->getValue('pho_description')) > 0)
                        {
                            $description = $childPhotoAlbum->getValue('pho_description');

                            if(strlen($description) > 400)
                            {
                                $description = substr($description, 0, 400) . ' ...';
                            }
                            $page->addHtml('<p class="card-text">' . $description . '</p>');
                        }

                        $page->addHtml('<p class="card-text">' . $childPhotoAlbum->countImages() . ' ' . $gL10n->get('PHO_PHOTOGRAPHER') . ' ' . $childPhotoAlbum->getValue('pho_photographers') . '</p>');
                        
                        // Notice for users with foto edit rights that the folder of the album doesn't exists
                        if (!is_dir($albumFolder) && !$childPhotoAlbum->hasChildAlbums() && $gCurrentUser->editPhotoRight())
                        {
                            $page->addHtml('<p class="card-text"><div class="alert alert-warning alert-small" role="alert"><i class="fas fa-exclamation-triangle"></i>'.$gL10n->get('PHO_FOLDER_NOT_FOUND').'</div></p>');
                        }
                
                        // Notice for users with foto edit right that this album is locked
                        if ($childPhotoAlbum->getValue('pho_locked') == 1)
                        {
                            $page->addHtml('<p class="card-text"><div class="alert alert-warning alert-small" role="alert"><i class="fas fa-exclamation-triangle"></i>'.$gL10n->get('PHO_ALBUM_NOT_APPROVED').'</div></p>');
                        }
                        
                        if ($gCurrentUser->editPhotoRight() && $childPhotoAlbum->getValue('pho_locked') == 1)
                        {
                            $page->addHtml('<button class="btn btn-primary" style="width: 50%;" onclick="window.location.href=\''.SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photo_album_function.php', array('pho_id' => (int) $childPhotoAlbum->getValue('pho_id'), 'mode' => 'unlock')).'\'">
                                '.$gL10n->get('PHO_ALBUM_UNLOCK').'
                            </button>');
                        }
        
                        $page->addHtml('</div>
                </div>
            </div>
        ');
    }//Ende wenn Ordner existiert
}//for

$page->addHtml('</div>');

/****************************Leeres Album****************/
// Falls das Album weder Fotos noch Unterordner enthaelt
if ($albumsCount === 0 && ($photoAlbum->getValue('pho_quantity') == 0 || strlen($photoAlbum->getValue('pho_quantity')) === 0))  // alle vorhandenen Albumen werden ignoriert
{
    $page->addHtml($gL10n->get('PHO_NO_ALBUM_CONTENT'));
}

// If necessary show links to navigate to next and previous albums of the query
$baseUrl = SecurityUtils::encodeUrl(ADMIDIO_URL.FOLDER_MODULES.'/photos/photos.php', array('pho_id' => $getPhotoId));
$page->addHtml(admFuncGeneratePagination($baseUrl, $albumsCount, $gSettingsManager->getInt('photo_albums_per_page'), $getStart));

// show html of complete page
$page->show();

<?php

/**
 * ProcessWire Get Video Thumbs
 * by Adrian Jones
 *
 * Automatically populates an images field with all available thumbnails from YouTube and Vimeo
 *
 * ProcessWire 3.x
 * Copyright (C) 2011 by Ryan Cramer
 * Licensed under GNU/GPL v2, see LICENSE.TXT
 *
 * http://www.processwire.com
 * http://www.ryancramer.com
 *
 */

class ProcessGetVideoThumbs extends WireData implements Module, ConfigurableModule {

    /**
     * getModuleInfo is a module required by all modules to tell ProcessWire about them
     *
     * @return array
     *
     */
    public static function getModuleInfo() {
        return array(
            'title' => __('Get Video Thumbnails'),
            'version' => '1.1.4',
            'summary' => __('Automatically populates an images field with thumbnails (poster images) from YouTube and Vimeo'),
            'author' => 'Adrian Jones',
            'href' => 'http://modules.processwire.com/modules/process-get-video-thumbs/',
            'singular' => true,
            'autoload' => true,
            'icon'     => 'youtube-play'
            );
    }


    protected static $configDefaults = array(
        // global
        "enabledTemplates" => "",
        "videoURLField" => "",
        "videoImagesField" => "",
        "youTubeImageNames" => "maxresdefault, sddefault, hqdefault, mqdefault, default, 0, 1, 2, 3",
        "vimeoImageNames" => "thumbnail_large, thumbnail_medium, thumbnail_small",
        "whichImages" => "firstAvailable"
    );


    /**
     * Data as used by the get/set functions
     *
     */
    protected $data = array();



    /**
     * Initialize the module
     *
     */
    public function init() {
    }

    public function ready() {
        $this->wire('pages')->addHookAfter('save', $this, 'importImages');
    }

    protected function importImages($event) {

        $page = $event->arguments[0];

        if(isset($this->enabledTemplates) && count($this->enabledTemplates) > 0 && !in_array($page->template->id, $this->enabledTemplates)) return;

        // if page doesn't contain the Video Images Field (where thumbnails are to be stored), exit now
        if(!$page->{$this->videoImagesField}) return;

        // populate array of videoIDs from the existing images
        if($page->{$this->videoImagesField}) {
            $existingImageIDs = array();
            foreach($page->{$this->videoImagesField} as $videoImage) {

                $imageVideoName = pathinfo($videoImage->filename,PATHINFO_FILENAME);

                if($this->whichImages != 'firstAvailable') {
                    $imageVideoNameArray = explode('-',$imageVideoName);
                    $imageVideoID = str_replace('video-', '', trim(str_replace(array_pop($imageVideoNameArray), '', $imageVideoName),'-'));
                }
                else{
                    $imageVideoID = str_replace('video-', '', $imageVideoName);
                }

                $existingImageIDs[] = $imageVideoID;
            }
        }

        // support FieldtypeTextareas exception
        // rename badly named singular settings variable to plural
        $videoURLFields = $this->videoURLField;
        foreach($videoURLFields as $videoURLFieldKey => $videoURLField) {
            $searchField = $this->wire('fields')->get($videoURLField);
            if($searchField->type == "FieldtypeTextareas") {
                unset($videoURLFields[$videoURLFieldKey]);
                foreach($searchField->type->getTextareaDefinitions($searchField) as $name => $definition) {
                    $textareasFieldName = $searchField->name . "." . $name;
                    $videoURLFields[] = $textareasFieldName;
                }
            }
        }

        $allVideos = array();

        foreach($videoURLFields as $videoURLField) {

            if($page->{$videoURLField}) {

                if($this->videoImagesField == '') return $this->error($this->_("Your module config is not fully configured. Please fill out the Video Images Field"));

                if(!$page->fields->get("{$this->videoImagesField}")) return $this->error($this->_("The template for this page does not contain the defined video images field. Please add the field that you defined in the module settings: {$this->videoImagesField}"));

                $videoURL = $page->{$videoURLField};

                // YOUTUBE
                // perform a strpos fast check before performing regex check
                if(strpos($videoURL, '://www.youtube.com/') !== false || strpos($videoURL, '://youtu.be/') !== false || strpos($videoURL, '://www.youtube-nocookie.com/') !== false) {
                    //modified from http://stackoverflow.com/a/17030234 to handle <p> tags around the url and -nocookie url
                    $regex = "/\s*(?:http(?:s)?:\/\/)?(?:www\.)?(?:youtu\.be\/|youtube(?:-nocookie)?\.com\/(?:(?:watch)?\?(?:.*&)?v(?:i)?=|(?:embed|v|vi|user)\/))([^\?#&\"'<>]+)/";
                    if(!preg_match_all($regex, $videoURL, $matches)) return;

                    foreach($matches[1] as $key => $line) {
                        $videoID = $matches[1][$key];
                        // populate array of video IDs for later cleanup of unnecessary images
                        $allVideos[] = strtolower($videoID);

                        // skip if we already have image(s) for this video
                        if(in_array(strtolower($videoID), $existingImageIDs)) continue;
                        $noMoreImages = 0;
                        foreach(preg_split('/[\.,\s]/', $this->youTubeImageNames, -1, PREG_SPLIT_NO_EMPTY) as $image_id) {
                            $title = null;
                            // copy images to PW images field
                            if($this->fileExists("http://i.ytimg.com/vi/".$videoID."/".$image_id.".jpg") && $noMoreImages == 0) {

                                $page->of(false);
                                $page->{$this->videoImagesField}->add("http://i.ytimg.com/vi/".$videoID."/".$image_id.".jpg");
                                $page->save($this->videoImagesField);
                                $currentImage = $page->{$this->videoImagesField}->get("name=$image_id.jpg");
                                $this->renameImage($page, $currentImage, $videoID, $image_id);
                                // add video title to thumbnail image description field
                                $http = new WireHttp();
                                $videoInfo = $http->get("http://youtube.com/get_video_info?video_id=".$videoID);
                                parse_str($videoInfo, $ytarr);
                                if(isset($ytarr['title'])) $title = stripslashes($ytarr['title']);

                                // add title to last image in field and save
                                if($title) {
                                    $page->{$this->videoImagesField}->last()->description = $title;
                                    $page->save($this->videoImagesField);
                                }

                                if($this->whichImages == 'firstAvailable') $noMoreImages = 1;
                            }

                        }
                    }
                }

                // VIMEO
                if(strpos($videoURL, '://vimeo.com/') !== false) {

                    if(!preg_match_all("/\s*(https?:\/\/vimeo.com\/(\d+)).*?/", $videoURL, $matches)) return;

                    foreach($matches[0] as $key => $line) {
                        $videoID = $matches[2][$key];

                        // populate array of video IDs for later cleanup of unnecessary images
                        $allVideos[] = strtolower($videoID);

                        // skip if we already have image(s) for this video
                        if(in_array(strtolower($videoID), $existingImageIDs)) continue;

                        $http = new WireHttp();
                        $data = $http->getJSON("http://vimeo.com/api/v2/video/".$videoID.".json");
                        $title = $data[0]['title'];

                        $noMoreImages = 0;
                        foreach(preg_split('/[\.,\s]/', $this->vimeoImageNames, -1, PREG_SPLIT_NO_EMPTY) as $image_name) {
                            // copy images to PW images field
                            if($this->fileExists($data[0][$image_name]) && $noMoreImages == 0) {
                                $page->{$this->videoImagesField}->add($data[0][$image_name]);
                                $page->of(false);
                                $page->save($this->videoImagesField);

                                $currentImage = $page->{$this->videoImagesField}->get("name=".pathinfo($data[0][$image_name], PATHINFO_BASENAME));
                                $this->renameImage($page, $currentImage, $videoID, $image_name);

                                // add title to last image in field and save
                                if($title) {
                                    $page->{$this->videoImagesField}->last()->description = $title;
                                    $page->save($this->videoImagesField);
                                }

                                if($this->whichImages == 'firstAvailable') $noMoreImages = 1;
                            }
                        }
                    }
                }
            }
        }

        // delete any images from videos that were removed from the text fields during this update
        if($page->{$this->videoImagesField}) {
            foreach($page->{$this->videoImagesField} as $videoImage) {

                $imageVideoName = pathinfo($videoImage->filename,PATHINFO_FILENAME);
                if(strpos($imageVideoName, 'video-') === false) continue;

                if($this->whichImages != 'firstAvailable') {
                    $imageVideoNameArray = explode('-',$imageVideoName);
                    $imageVideoID = str_replace('video-', '', trim(str_replace(array_pop($imageVideoNameArray), '', $imageVideoName),'-'));
                }
                else{
                    $imageVideoID = str_replace('video-', '', $imageVideoName);
                }

                if(!in_array($imageVideoID, $allVideos)) {
                    $page->{$this->videoImagesField}->delete($videoImage);
                    $page->of(false);
                    $page->save($this->videoImagesField);
                }

            }
        }

    }

    /**
     * Helper function to rename images
     *
     */
    private function renameImage($page, $currentImage, $videoID, $image_id) {

        $newImgName = pathinfo($currentImage->filename, PATHINFO_DIRNAME) . "/video-".strtolower($videoID).($this->whichImages != "firstAvailable" ? "-". $image_id : "") .".jpg";

        // copy and rename
        copy($currentImage->filename, $newImgName);

        // remove old, add new image to page
        $page->{$this->videoImagesField}->remove($currentImage); // orig, will get deleted
        $page->{$this->videoImagesField}->add($newImgName); // new
        $page->of(false);
        $page->save($this->videoImagesField);

    }

    /**
     * Helper function to check if image exists on remote server
     *
     */
    private function fileExists($path) {
        $file_headers = @get_headers($path);
        if (strpos($file_headers[0], '200') === false) {
            return false;
        }
        else {
            return true;
        }
    }



    /**
     * Get any inputfields used for configuration of this Fieldtype.
     *
     * This is in addition to any configuration fields supplied by the parent Inputfield.
     *
     * @param Field $field
     * @return InputfieldWrapper
     *
     */
    public function getModuleConfigInputfields(array $data) {

        $modules = $this->wire('modules');

        foreach(self::$configDefaults as $key => $value) {
            if(!isset($data[$key]) || $data[$key]=='') $data[$key] = $value;
        }

        $inputfields = new InputfieldWrapper();

        $f = $modules->get("InputfieldAsmSelect");
        $f->attr('name', 'enabledTemplates');
        $f->attr('value', $data["enabledTemplates"]);
        $f->label = __('Templates to search');
        $f->columnWidth = 50;
        $f->description = __('The template(s) to search for video URLs. If none selected, all will be searched.');
        $f->setAsmSelectOption('sortable', false);
        // populate with all available fields
        foreach($this->wire('templates') as $template) {
            $f->addOption($template->id, $template->label ? $template->label : $template->name);
        }
        if(isset($data['enabledTemplates'])) $f->value = $data['enabledTemplates'];
        $inputfields->add($f);

        $f = $modules->get("InputfieldAsmSelect");
        $f->required = true;
        $f->attr('name', 'videoURLField');
        $f->attr('value', $data["videoURLField"]);
        $f->label = __('Fields to search');
        $f->columnWidth = 50;
        $f->description = __('The field(s) to search for video URLs.');
        $f->setAsmSelectOption('sortable', false);
        // populate with all available fields
        foreach($this->wire('fields') as $fieldoption) {
            // filter out incompatible field types
            if($fieldoption->type instanceof FieldtypeText)  $f->addOption($fieldoption->name);
        }
        if(isset($data['videoURLField'])) $f->value = $data['videoURLField'];
        $inputfields->add($f);


        $f = $modules->get("InputfieldSelect");
        $f->required = true;
        $f->attr('name', 'videoImagesField');
        $f->attr('value', $data["videoImagesField"]);
        $f->label = __('Video Images Field');
        $f->description = __('The field to send the video thumbnail images to.');
        $f->notes = __('Note: Only image fields with "Maximum files allowed" set to "0" are allowed and listed here.');
        $f->addOption('');
        // populate with all available fields
        foreach($this->wire('fields') as $fieldoption) {
            // filter out incompatible field types
            if($fieldoption->type instanceof FieldtypeImage && $fieldoption->maxFiles == 0)  $f->addOption($fieldoption->name);
        }
        if(isset($data['videoImagesField'])) $f->value = $data['videoImagesField'];
        $inputfields->add($f);

        $f = $modules->get("InputfieldText");
        $f->attr('name', 'youTubeImageNames');
        $f->attr('value', $data["youTubeImageNames"]);
        $f->attr('size', 70);
        $f->label = __('YouTube Image Names');
        $f->description = __('The names of the images you want to get. You can list as many of the options as you wish.');
        $f->notes = __("Default: maxresdefault, sddefault, hqdefault, mqdefault, default, 0, 1, 2, 3");
        $inputfields->add($f);

        $f = $modules->get("InputfieldText");
        $f->attr('name', 'vimeoImageNames');
        $f->attr('value', $data["vimeoImageNames"]);
        $f->attr('size', 70);
        $f->label = __('Vimeo Image Names');
        $f->description = __('The names of the images you want to get. You can list as many of the options as you wish.');
        $f->notes = __("Default: thumbnail_large, thumbnail_medium, thumbnail_small");
        $inputfields->add($f);

        $f = $modules->get("InputfieldSelect");
        $f->attr('name', 'whichImages');
        $f->attr('value', $data["whichImages"]);
        $f->addOption('firstAvailable', __('First Available'));
        $f->addOption('allAvailable', __('All Available'));
        $f->attr('value', $data["whichImages"]);
        $f->label = __('Which Images');
        $f->description = __('Whether you want all the listed images grabbed, or just the first one that is available from those listed in the Image Names fields above.');
        $inputfields->add($f);

        return $inputfields;

    }

}

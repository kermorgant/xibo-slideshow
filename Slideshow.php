<?php
/*
 * Xibo - Digital Signage - http://www.xibo.org.uk
 * Copyright (C) 2006-2015 Daniel Garner
 *
 * This file is part of Xibo.
 *
 * Xibo is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * any later version.
 *
 * Xibo is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with Xibo.  If not, see <http://www.gnu.org/licenses/>.
 *
 */
namespace Xibo\Custom;

use Respect\Validation\Validator as v;
use Xibo\Exception\NotFoundException;
use Xibo\Factory\ModuleFactory;
use Xibo\Widget\ModuleWidget;

class Slideshow extends ModuleWidget
{
    private $resourceFolder;
    protected $codeSchemaVersion = 1;

    /**
     * Slideshow constructor.
     */
    public function init()
    {
        $this->resourceFolder = PROJECT_ROOT . '/web/modules/slideshow';

        // Initialise extra validation rules
        v::with('Xibo\\Validation\\Rules\\');
    }

    /**
     * Template for Layout Designer JavaScript
     * @return string
     */
    public function layoutDesignerJavaScript()
    {
        return 'slideshow-form-js';
    }


    /**
     * Install or Update this module
     * @param ModuleFactory $moduleFactory
     */
    public function installOrUpdate($moduleFactory)
    {
        if ($this->module == null) {
            // Install
            $module = $moduleFactory->createEmpty();
            $module->name = 'Slideshow';
            $module->type = 'slideshow';
            $module->class = 'Xibo\Custom\Slideshow';
            $module->description = 'Slideshow with jquery cycle plugin';
            $module->imageUri = 'forms/library.gif';
            $module->enabled = 1;
            $module->previewEnabled = 0;
            $module->assignable = 1;
            $module->regionSpecific = 1;
            $module->renderAs = 'html';
            $module->schemaVersion = $this->codeSchemaVersion;
            $module->settings = [];
            $module->defaultDuration = 60;
            $module->viewPath = '../custom/slideshow';

            $this->setModule($module);
            $this->installModule();
        }

        // Check we are all installed
        //$this->installFiles();
    }

    public function installFiles()
    {
        $this->mediaFactory->createModuleSystemFile(PROJECT_ROOT . '/web/modules/jquery-cycle-2.1.6.min.js')->save();
        $this->mediaFactory->createModuleSystemFile(PROJECT_ROOT . '/web/modules/xibo-layout-scaler.js')->save();
    }

    public function validate()
    {
        if ($this->getOption('imageDuration', 0) == 0)
            throw new \InvalidArgumentException(__('Please enter a duration for each image'));
        elseif ($this->getOption('imageDuration', 0) < 100)
            throw new \InvalidArgumentException(__('Duration is in milliseconds !'));

        if (count($this->getMediaList()) < 2)
            throw new \InvalidArgumentException(__('Please add at least 2 images' ));
        elseif (count($this->getMediaList()) > 10)
            throw new \InvalidArgumentException(__('Please select less than 10 images' ));
    }


    public function getMediaList()
    {
        $mediaListString = $this->getOption('mediaList', null);
        $mediaListString = str_replace(array('[',']'), '', $mediaListString);

        return explode(',',$mediaListString);
    }

    /**
     * Add Media to the Database
     */
    public function add()
    {
        $this->setCommonOptions();

        //Save the widget
        $this->validate();
        $this->saveWidget();
    }

    /**
     * Edit Media in the Database
     */
    public function edit()
    {
        $this->setCommonOptions();

        // Save the widget
        $this->validate();
        $this->saveWidget();
    }

    /**
     * Setting of common options for the add & edit forms
     */
    public function setCommonOptions()
    {
        $this->setUseDuration(true); // we won't use media duration

        $imageDuration = $this->getSanitizer()->getInt('imageDuration', 4000);

        $mediaListString = $this->getSanitizer()->getString('mediaList');

        $this->setOption('imageDuration', $imageDuration);
        $this->setOption('mediaList', $mediaListString);
        $this->setOption('effect', $this->getSanitizer()->getString('effect'));

        // set duration according to user input or sum of media duration
        $mediaList = explode(',', $mediaListString);
        $duration = ((count($mediaList) * $imageDuration) / 1000);

        $this->setDuration($duration);
    }


    /**
     * Get Resource
     * @param int $displayId
     * @return mixed
     */
    public function getResource($displayId = 0)
    {
        $data = [];
        $isPreview = ($this->getSanitizer()->getCheckbox('preview') == 1);

        // Clear all linked media.
        $this->clearMedia();

        // Replace the View Port Width?
        $data['viewPortWidth'] = ($isPreview) ? $this->region->width : '[[ViewPortWidth]]';

        $duration = $this->getCalculatedDurationForGetResource();

        // Replace the head content
        $javaScriptContent = '<script type="text/javascript" src="' . $this->getResourceUrl('vendor/jquery-1.11.1.min.js') . '"></script>';
        $javaScriptContent .= '<script type="text/javascript" src="' . $this->getResourceUrl('vendor/jquery-cycle-2.1.6.min.js') . '"></script>';
        $javaScriptContent .= '<script type="text/javascript" src="' . $this->getResourceUrl('xibo-layout-scaler.js') . '"></script>';

        // Update and save widget if we've changed our assignments.
        if ($this->hasMediaChanged())
            $this->widget->save(['saveWidgetOptions' => false, 'notifyDisplays' => true]);

        $i = 0;
        $body = <<<'EOD'
<div class="cycle-slideshow"
    data-cycle-fx=##EFFECT##
    data-cycle-timeout=##IMAGE_DURATION##
    data-cycle-loader=true
    data-cycle-progressive="#images2"
    >
EOD;
        $body = str_replace('##EFFECT##',
                            $this->getOption('effect', 'tileBlind'),
                            $body);
        $body = str_replace('##IMAGE_DURATION##',
                            $this->getOption('imageDuration', 4000),
                            $body);

        $mediaList = explode(',',$this->parseLibraryReferences($isPreview, $this->getOption('mediaList', null)));


        $body .= '<img src="' . array_shift($mediaList) . '" style="width: 100%; height: auto;"/>';

        $body .= '<script id="images2" type="text/cycle">';
        foreach($mediaList as $media) {
            $body .= '<img src="' . $media . '" style="width: 100%; height: auto;"/>' . "\n";
        }
        $body .= '</script></div>';

        $data['javaScript'] = $javaScriptContent;
        $data['body'] = $body;

        // Return that content.
        return $this->renderTemplate($data);
    }


    public function isValid()
    {
        // Using the information you have in your module calculate whether it is valid or not.
        // 0 = Invalid
        // 1 = Valid
        // 2 = Unknown
        return 1;
    }
}

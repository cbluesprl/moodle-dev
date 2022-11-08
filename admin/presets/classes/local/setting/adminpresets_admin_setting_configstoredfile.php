<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace core_adminpresets\local\setting;

use admin_setting;
use coding_exception;
use context_system;
use core_filetypes;
use dml_exception;
use moodle_exception;
use stdClass;

/**
 * Adds support for the "configstoredfile" attribute.
 * The setting saved in preset contains the contents of the file in base64, the file extension and the file name.
 *
 * @package          core_adminpresets
 * @copyright        2022 Piton Olivier <olivier@cblue.be>
 * @author           Piton Olivier
 * @license          http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class adminpresets_admin_setting_configstoredfile extends adminpresets_setting {

    protected string $valuecurrent = ''; // Base64 content.
    protected array $filescurrent = []; // Current files (from DB).
    protected array $filesnew = []; // New files (from preset).

    protected string $fileinfoseparator = '_-fileinfoseparator-_';
    protected string $multiplefilesseparator = '_-multiplefilesseparator-_';

    protected static array $imageextensions = []; // Used in the display to display images.

    protected static array $fileareamapping = [
        'theme_boost' => [
            'presetfiles' => 'preset'
        ]
    ];

    /**
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function __construct(admin_setting $settingdata, $dbsettingvalue) {
        if ($settingdata->plugin == 'none' || $settingdata->plugin == '') {
            throw new moodle_exception('errorsetting', 'core_adminpresets', '', null, 'cannot handle non plugin files');
        }

        self::loadImageExtensions();

        if (!empty($dbsettingvalue)) {
            if (!$this->isBase64($dbsettingvalue)) {
                $this->manageFilesCurrent($settingdata->plugin, $settingdata->name);
            } else {
                $this->manageFilesNew($dbsettingvalue);
            }
        }

        parent::__construct($settingdata, $dbsettingvalue);
    }

    protected function set_value($value): void {
        if (!empty($this->valuecurrent)) {
            $this->value = $this->valuecurrent;
        } else {
            $this->value = $value;
        }

        $this->set_visiblevalue();
    }

    protected function set_visiblevalue(): void {
        $this->visiblevalue = '';

        if (!empty($this->filescurrent)) {
            foreach ($this->filescurrent as $file) {
                if ($this->isImageExtension($file->fileextension)) {
                    $this->visiblevalue .= '<img style="display:block; width:200px" src="data:' . $file->fileextension . ';base64, ' . $file->filecontent . '" />';
                } else {
                    $this->visiblevalue .= '<span style="display:block; width: 200px; overflow: hidden; text-overflow: ellipsis;"><b>' .
                        $file->filename . '</b> ' . substr(base64_decode($file->filecontent), 0, 100) . '...</span>';
                }
            }
        } else {
            foreach ($this->filesnew as $file) {
                if ($this->isImageExtension($file->fileextension)) {
                    $this->visiblevalue .= '<img style="display:block; width:200px" src="data:' . $file->fileextension . ';base64, ' . $file->filecontent . '" />';
                } else {
                    $this->visiblevalue .= '<span style="display:block; width: 200px; overflow: hidden; text-overflow: ellipsis;"><b>' .
                        $file->filename . '</b> ' . substr(base64_decode($file->filecontent), 0, 100) . '...</span>';
                }
            }
        }
    }

    /**
     * Stores the setting into database, logs the change and returns the config_log inserted id.
     *
     * @throws dml_exception
     * @throws moodle_exception
     */
    public function save_value($name = false, $value = null) {
        if ($value === null) {
            $value = $this->value;
        }
        if (!$name) {
            $name = $this->settingdata->name;
        }

        $plugin = $this->settingdata->plugin;
        if ($plugin == 'none' || $plugin == '') {
            throw new moodle_exception('errorsetting', 'core_adminpresets', '', null, 'cannot handle non plugin files');
        }

        $fs = get_file_storage();

        $filearea = $this->fileareaMapping($plugin, $name);

        $fs->delete_area_files(
            context_system::instance()->id,
            $plugin,
            $filearea,
            0
        );

        $firstfilename = ''; // Moodle does not store all uploaded file names, just the first one.

        foreach (explode($this->multiplefilesseparator, $value) as $file) {
            if (empty($file)) {
                continue;
            }

            $f = new stdClass();
            [$f->filecontent, $f->fileextension, $f->filename] = explode($this->fileinfoseparator, $file);

            if (empty($firstfilename)) {
                $firstfilename = $f->filename;
            }

            $fileinfo = [
                'contextid' => context_system::instance()->id,
                'component' => $plugin,
                'filearea' => $filearea,
                'itemid' => 0,
                'filepath' => '/',
                'filename' => $f->filename
            ];
            $fs->create_file_from_string($fileinfo, base64_decode($f->filecontent));
        }

        if ($firstfilename) {
            set_config($name, '/' . $firstfilename, $plugin);
            return $this->to_log($plugin, $name, $firstfilename, get_config($plugin, $name));
        }

        return false;
    }

    protected function isImageExtension(string $extension): bool {
        return !empty(self::$imageextensions['.' . $extension]);
    }

    protected function fileareaMapping(string $plugin, string $name): string {
        if (isset(self::$fileareamapping[$plugin])) {
            if (isset(self::$fileareamapping[$plugin][$name])) {
                return self::$fileareamapping[$plugin][$name];
            }
        }

        return $name;
    }

    /**
     * @throws coding_exception
     * @throws dml_exception
     */
    protected function manageFilesCurrent(string $plugin, string $name): void {
        $fs = get_file_storage();

        $files = $fs->get_area_files(
            context_system::instance()->id,
            $plugin,
            $this->fileareaMapping($plugin, $name),
            0,
            'id',
            false
        );

        foreach ($files as $file) {
            $info = pathinfo($file->get_filename());

            $f = new stdClass();
            $f->filecontent = base64_encode($file->get_content());
            $f->fileextension = $info['extension'];
            $f->filename = $info['basename'];

            $this->filescurrent[] = $f;

            $this->valuecurrent .=
                $f->filecontent . $this->fileinfoseparator .
                $f->fileextension . $this->fileinfoseparator .
                $f->filename . $this->multiplefilesseparator;
        }
    }

    protected function manageFilesNew($value): void {
        $files = explode($this->multiplefilesseparator, $value);
        foreach ($files as $file) {
            if (empty($file)) {
                continue;
            }
            $f = new stdClass();
            [$f->filecontent, $f->fileextension, $f->filename] = explode($this->fileinfoseparator, $file);
            $this->filesnew[] = $f;
        }
    }

    protected function isBase64(string $string): bool {
        return substr($string, -strlen($this->multiplefilesseparator)) === $this->multiplefilesseparator;
    }

    protected static function loadImageExtensions(): void {
        if (empty(self::$imageextensions)) {
            $groups = [];

            foreach (core_filetypes::get_types() as $ext => $data) {
                if (isset($data['groups']) && is_array($data['groups'])) {
                    foreach ($data['groups'] as $group) {
                        if ($group !== 'image') {
                            continue;
                        }
                        if (!isset($groups[$group])) {
                            $groups[$group] = new stdClass();
                            $groups[$group]->extensions = [];
                            $groups[$group]->mimetypes = [];
                        }
                        $groups[$group]->extensions['.' . $ext] = true;
                        if (isset($data['type'])) {
                            $groups[$group]->mimetypes[$data['type']] = true;
                        }
                    }
                }
            }

            self::$imageextensions = $groups['image']->extensions;
        }
    }
}

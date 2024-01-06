<?php

use MX\MX_Controller;

class Modules extends MX_Controller
{
    private $coreModules;

    public function __construct()
    {
        parent::__construct();

        $this->coreModules = array('admin', 'login', 'logout', 'errors', 'news', 'mod');

        $this->load->library('administrator');
        $this->load->helper('file');
        $this->load->library('upload');
        require_once('application/libraries/Prettyjson.php');

        requirePermission("view");
    }

    public function index()
    {
        $this->administrator->setTitle("Modules");

        $this->administrator->loadModules();

        $data = array(
            'url' => $this->template->page_url,
            'enabled_modules' => $this->administrator->getEnabledModules(),
            'disabled_modules' => $this->administrator->getDisabledModules()
        );

        $output = $this->template->loadPage("modules.tpl", $data);

        $content = $this->administrator->box('Modules', $output);

        $this->administrator->view($content, "modules/admin/css/modules.css", "modules/admin/js/modules.js");
    }

    public function enable($moduleName)
    {
        requirePermission("toggleModules");

        $this->changeManifest($moduleName, "enabled", true);

        die('SUCCESS');
    }

    public function disable($moduleName)
    {
        requirePermission("toggleModules");

        if (!in_array($moduleName, $this->coreModules)) {
            $this->changeManifest($moduleName, "enabled", false);

            die('SUCCESS');
        } else {
            die('CORE');
        }
    }

    public function changeManifest($moduleName, $setting, $newValue)
    {
        requirePermission("editModuleConfigs");

        $filePath = "application/modules/" . $moduleName . "/manifest.json";
        $manifest = json_decode(file_get_contents($filePath), true);

        // Replace the setting with the newValue
        $manifest[$setting] = $newValue;

        $prettyJSON = new PrettyJSON($manifest);

        // Rewrite the file with the new data
        $fileHandle = fopen($filePath, "w");
        fwrite($fileHandle, $prettyJSON->get());
        fclose($fileHandle);
    }

    public function upload()
    {
        ini_set('memory_limit', '5120M');
        set_time_limit(0);

        if (isset($_FILES['module'])) {
            if (!empty($_FILES['module']["name"])) {
                $_FILES['file']['name'] = time() . "_" . $_FILES['module']['name'];
                $_FILES['file']['type'] = $_FILES['module']['type'];
                $_FILES['file']['tmp_name'] = $_FILES['module']['tmp_name'];
                $_FILES['file']['error'] = $_FILES['module']['error'];
                $_FILES['file']['size'] = $_FILES['module']['size'];

                // Set preference
                $config['upload_path'] = FCPATH . 'writable/uploads/modules';
                $config['allowed_types'] = 'zip';
                $config['overwrite'] = true;
                $config['max_size'] = '1000000';
                $config['file_name'] = $_FILES['file']['name'];

                $this->upload->initialize($config);

                if (!is_dir($config['upload_path'])) {
                    mkdir($config['upload_path'], 0777, true);
                }

                // File upload
                if (!$this->upload->do_upload('file')) {
                    die(json_encode(array('status' => 'error', 'message' => $this->upload->display_errors())));
                } else {
                    $m_data = $this->upload->data();
                    $filename = $m_data['file_name'];

                    ## Extract the zip file
                    $zip = new ZipArchive();
                    $res = $zip->open($config['upload_path'] . '/' . $filename);
                    if ($res === true) {
                        // Extract file
                        $zip->extractTo(FCPATH . 'temp/modules/');
                        register_shutdown_function(function() { $this->removeDir(FCPATH . 'temp'); });

                        $modules = array();

                        foreach (glob(FCPATH . 'temp/modules/*') as $file)
                        {
                            if(!is_dir($file))
                            {
                                unlink($file);
                                continue;
                            }

                            $name = preg_replace('/temp\/modules\//', '', $file);

                            if (!file_exists($file . '/manifest.json')) {
                                die(json_encode(array('status' => 'error', 'message' => 'The module <b>' . $name . '</b> is missing manifest.json')));
                            }

                            if (!is_dir($file . '/controllers')) {
                                die(json_encode(array('status' => 'error', 'message' => 'The module <b>' . $name . '</b> is missing controllers')));
                            }

                            $modules[] = basename($name);

                            foreach (glob(FCPATH . 'temp/modules/' . basename($name) . '/sql/*.sql') as $sqlname) {
                                if (file_exists($sqlname)) {
                                    $lines = file($sqlname);
                                    $statement = '';
                                    foreach ($lines as $line) {
                                        $statement .= $line;
                                        if (str_ends_with(trim($line), ';')) {
                                            try {
                                                $this->db->query($statement);
                                                $statement = '';
                                            } catch (Throwable $t) {
                                                unlink($sqlname);
                                                die(json_encode(array('status' => 'error', 'message' => 'SQL import failed')));
                                            }
                                        }
                                    }
                                    unlink($sqlname);
                                }
                            }
                            register_shutdown_function(function() use ($name) { $this->removeDir(FCPATH . 'application/modules/' . basename($name) . '/sql'); });
                        }

                        if(!is_array($modules) || !count($modules))
                            die(json_encode(array('status' => 'error', 'message' => 'The module is not valid')));

                        // Extract file
                        $zip->extractTo(FCPATH . 'application/modules/');
                        $zip->close();

                        if (!unlink($config['upload_path'] . '/' . $filename)) {
                            die(json_encode(array('status' => 'error', 'message' => 'Failed to delete uploaded zip file, but extraction worked')));
                        }

                        die(json_encode(array('status' => 'success', 'message' => 'Module successfully uploaded')));
                    } else {
                        die(json_encode(array('status' => 'error', 'message' => 'Failed to extract')));
                    }
                }
            }
        }
    }

    /**
     * Remove dir
     * Destroys a directory
     *
     * @param string $dir
     * @return void
     */
    private function removeDir(string $dir): void
    {
        if(!is_dir($dir))
            return;

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach($files as $file)
            ($file->isDir()) ? rmdir($file->getRealPath()) : unlink($file->getRealPath());

        rmdir($dir);
    }
}

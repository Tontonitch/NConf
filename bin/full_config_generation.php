<?php

    chdir("/Monitoring/icinga-addons/nconf-1.3.0-0");
    require_once 'main.php';
    
    #---------------------------------------------------------
    # Lock file management (from generate_config.php)
    #---------------------------------------------------------
    
    $lock_file = 'temp/generate.lock';
    $status = check_file('file_exists', $lock_file, TRUE, "File/Directory still exists, please remove it: "); 
    
    if ( $status ){
        # lock file exists
    
        $lock_file_age = ( time() - filemtime($lock_file) );
        echo "Lock file last set since " . $lock_file_age . " seconds\n";
        echo "Next execution should be available in " . (600-$lock_file_age) . " seconds\n";
        echo "Force remove lock? Remove the 'generate.lock' in the 'temp' directory\n";
    
        # check if file is older than 10min(600sec) so replace it (should not be, because file will be removed)
        # but this will prevent lock file to stay there
        if ( $lock_file_age < 600 ){
            # some one other is generating the config
            echo "ERROR: someone else is already generating the configuration";
            $status = "error";
            exit(1);
    
        }else{
            # remove lock file
            $unlink_status = unlink($lock_file);
            if (!$unlink_status){
                echo "ERROR: removing old lock failed";
                $status = "error";
                exit(1);
            }
        }
        # if file is older, script will continue, and try to get lock again
    
    }
    
    # create lock
    $generate_lock_handle = fopen($lock_file, 'w');
    $status = flock($generate_lock_handle, LOCK_EX | LOCK_NB); //lock the file
    
    #---------------------------------------------------------
    # Config generation (from exec_generate_config.php)
    #---------------------------------------------------------
    
    # Remove lock file if process fails
    $lock_file = 'temp/generate.lock';
    function remove_lock(){
        # remove lock file
        global $lock_file;
        $unlink_status = unlink($lock_file);
        if (!$unlink_status){
            echo "ERROR: removing old lock failed";
            $status = "error";
            exit(1);
        }
    }

    history_add("general", "config", "generating...");

    // predefine status as OK
    $status = "OK";

    // check if "temp" dir is writable
    if(!is_writable(NCONFDIR."/temp/")){
        echo "ERROR: Could not write to 'temp' folder. Cannot generate config.";
        remove_lock();
        $status = "error";
        exit(1);
    }

    // check if "output" dir is writable
    if(!is_writable(NCONFDIR."/output/")){
        echo "ERROR: Could not write to 'output' folder. Cannot store generated config.";
        remove_lock();
        $status = "error";
        exit(1);
    }

    // check if generate_config script is executable
    if(!is_executable(NCONFDIR."/bin/generate_config.pl")){
        echo "ERROR: Could not execute generate_config script. <br>The file '".NCONFDIR."/bin/generate_config.pl' is not executable.";
        remove_lock();
        $status = "error";
        exit(1);
    }

    // check if the Nagios / Icinga binary is executable
    exec(NAGIOS_BIN,$bin_out);
    if(!preg_match('/Nagios|Icinga/',implode(' ',$bin_out))){
        echo "ERROR: Error accessing or executing Nagios / Icinga binary '".NAGIOS_BIN."'. <br>Cannot run the mandatory syntax check.";
        remove_lock();
        $status = "error";
        exit(1);
	}

    // check if existing "output/NagiosConfig.tgz" is writable
    if(file_exists(NCONFDIR."/output/NagiosConfig.tgz" and !is_writable(NCONFDIR."/output/NagiosConfig.tgz"))){
        echo "ERROR: Cannot rename ".NCONFDIR."/output/NagiosConfig.tgz. Access denied.";
        remove_lock();
        $status = "error";
        exit(1);
    }

    // check if static config folder(s) are readable
    foreach ($STATIC_CONFIG as $static_folder){
        if(!is_readable($static_folder)){
            echo "ERROR: Could not access static config folder '".$static_folder."'. Check your \$STATIC_CONFIG array in 'config/nconf.php'.";
            remove_lock();
            $status = "error";
            exit(1);
        }
    }

    // fetch all monitor and collector servers from DB
    $servers = array();
    $query = "SELECT fk_id_item AS item_id,attr_value,config_class
                  FROM ConfigValues,ConfigAttrs,ConfigClasses
                  WHERE id_attr=fk_id_attr
                      AND naming_attr='yes'
                      AND id_class=fk_id_class
                      AND (config_class = 'nagios-collector' OR config_class = 'nagios-monitor') 
                  ORDER BY attr_value";

    $result = db_handler($query, "result", "fetch all monitor and collector servers from DB");

    while ($entry = mysql_fetch_assoc($result) ){
        $renamed = preg_replace('/-|\s/','_',$entry["attr_value"]);

        if($entry["config_class"] == 'nagios-collector'){
            $renamed = preg_replace('/Nagios|Icinga/i','collector',$renamed);
        }
        array_push($servers, $renamed);
    }

    # GENERATE CONFIG
    echo "\n\n ==> Generate config <==\n";
    
    //system(NCONFDIR."/bin/generate_config.pl");
    $command = NCONFDIR."/bin/generate_config.pl";
    $output = array();
    exec($command, $output);

    // print each line
    foreach ($output AS $line){
        // Filter some lines:
        if ( empty($line)) continue;
        if ( strpos($line, "Copyright")) continue;
        if ( strpos($line, "Initializing")) continue;
        echo "$line\n";
    }

    // create tar file
    system("cd ".NCONFDIR."/temp; tar -cf NagiosConfig.tar global ".implode(" ", $servers));

    // add folders with static config to tar file           
    foreach ($STATIC_CONFIG as $static_folder){
       if(!is_empty_folder($static_folder) and is_empty_folder($static_folder) != "error"){
           $last_folder = basename($static_folder);
           system("cd ".$static_folder."; cd ../; tar -rf ".NCONFDIR."/temp/NagiosConfig.tar ".$last_folder);
       }
    }

    // compress tar file
    system("cd ".NCONFDIR."/temp; gzip NagiosConfig.tar; mv NagiosConfig.tar.gz NagiosConfig.tgz");
    
    # SYNTAX CHECK - now run tests on all generated files
    echo "\n\n ==> Running syntax check <==\n";

    $details = '';
    $break = "  -  ";
    foreach ($servers as $server){
        $server_str = preg_replace("/\./", "_", $server);

        # run test
        exec(NAGIOS_BIN." -v ".NCONFDIR."/temp/test/".$server.".cfg",$srv_summary[$server]);

        $total_msg = '';
        $count=0;
        $i = 0;
        foreach($srv_summary[$server] as $line){
            if( preg_match("/^Total/",$line) ){
                # add splitter between messages
                $total_msg .= ( $i > 0 ) ? $break : '';
                $i++;
                $total_msg .= $line;
                $count++;
                if( preg_match("/Errors/",$line) && !preg_match('/Total Errors:\s+0/',$line)){
                    $status = "error";
                }
            }
        }
        if($count==0){
            $total_msg .= "Error generating config";
            $status = "error";
        }

        // print server info
        echo "\n".$server_str.$total_msg."\n";
        foreach($srv_summary[$server] as $line){
            echo "$line\n";
        }
    }
    
    if($status == "OK"){
        history_add("general", "config", "generated successfully");

        // Move generated config to "output" dir
        if(file_exists(NCONFDIR."/output/NagiosConfig.tgz")){
            system("mv ".NCONFDIR."/output/NagiosConfig.tgz ".NCONFDIR."/output/NagiosConfig.tgz.".time());
        }
        system("mv ".NCONFDIR."/temp/NagiosConfig.tgz ".NCONFDIR."/output/");
        system("rm -rf ".NCONFDIR."/temp/*");

        #---------------------------------------------------------
        # Config deployment
        #---------------------------------------------------------

        if(ALLOW_DEPLOYMENT == 1){
            echo "\n\n ==> Deploy generated config <==\n";

            // check  if new deployment is configured
            $deployment_config = NCONFDIR.'/config/deployment.ini';
            $deployment_info = FALSE;
            if ( !file_exists($deployment_config) ){
                $deployment_info = TRUE;
            }elseif( is_readable($deployment_config) ){
                $ini_array = parse_ini_file($deployment_config, TRUE);
                if ( empty($ini_array) ){
                    $deployment_info = TRUE;
                }
            }
            if ($deployment_info){
                echo "Note: The generated configuration has been written to the \"nconf/output/\" directory.\nTo set up more sophisticated deployment functionality, please edit your \"config/deployment.ini\" file accordingly.\nFor a complete list of available deployment options, refer to the online documentation on\nhttp://www.nconf.org";
                exit(0);
            }else{
                // Deploy
                $_POST["status"] = $status;
                // Load deployment class and create object
                require_once("include/modules/deployment/class.deployment.php");
                require_once("include/modules/deployment/class.deployment.modules.php");
                
                // Load the NConf Deployment class
                // It loads all the modules and handles the deployment basic stuff
                $deployment = new NConf_Deployment();
            
                // Loads the configuration of the user
                // nconf/conf/deployment.ini
                $deployment->import_config();
            
                if ( NConf_DEBUG::status('ERROR') ) {
                    // Show error if set
                    echo NConf_HTML::limit_space( NConf_HTML::show_error() );
                }else{
                    // Start deploying the files
                    ob_start();
                    $deployment->run_deployment();
                    $deployment_output = ob_get_contents();;
                    ob_end_clean();
                    #$deployment_output = preg_replace("/alt=\"expand\">/", "alt=\"expand\">- ", $deployment_output);
                    #$deployment_output = preg_replace("/<b>/", "<b>* ", $deployment_output);
                    $deployment_output = strip_tags($deployment_output);
                    $deployment_output = preg_replace("/\n\s*\n/", "\n", $deployment_output);
                    $deployment_output = preg_replace("/\n\s{25,60}/", "\n * ", $deployment_output);
                    $deployment_output = preg_replace("/\n\s{23,60}/", "\n  - ", $deployment_output);
                    $deployment_output = preg_replace("/\n\s{10,60}/", " ", $deployment_output);
                    $deployment_output = preg_replace("/: OK/", ": OK\n       ", $deployment_output);
                    $deployment_output = preg_replace("/: FAILED/", ": FAILED\n       ", $deployment_output);
                    $deployment_output = preg_replace("/call OK/", "call OK\n       ", $deployment_output);
                    $deployment_output = preg_replace("/call FAILED/", "call FAILED\n       ", $deployment_output);
                    echo "$deployment_output\n";
                }
            
            }

        }else{
            // Simply show success message
            echo "Changes updated successfully.";
        }

    }else{
        history_add("general", "config", "generate failed with syntax errors");
        // Remove generated config - syntax check has failed
        if(DEBUG_MODE == 1){
            // Move generated config to "output" dir, but tag it as FAILED
            system("mv ".NCONFDIR."/temp/NagiosConfig.tgz ".NCONFDIR."/output/NagiosConfig_FAILED.tgz.".time());
        }
        // Remove generated config
        system("rm -rf ".NCONFDIR."/temp/*");
        echo "ERROR: Deployment not possible due to errors in configuration.";
        $status = "error";
        exit(1);
    }

    mysql_close($dbh);

?>

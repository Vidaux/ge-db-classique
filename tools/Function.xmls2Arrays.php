<?php

function SaXtA($filenames, $ArrayName, $ExcludeAttrib="") {
	
    $EAttribs=explode(" ", $ExcludeAttrib);
     $list = explode(" ", $filenames);
    global ${$ArrayName};
    ${$ArrayName} = [];
	    foreach ($list as $filename) {
        $filename = trim($filename);
        if ($filename === '' || !file_exists($filename)) {
            continue;
        }
        $objector = fopen($filename, 'r');
        if ($objector === false) {
            continue;
        }
        while (($buffer = fgets($objector)) !== false) {
            $matches = [];
            if (strpos($buffer, "ClassID=") != "") {
                preg_match('/ClassID="(.*)"/Um', $buffer, $matches);
                $ClassID = $matches[1];
                unset ($matches);
                if (preg_match_all('/ (.*)="(.*)"/Um', $buffer, $matches, PREG_SET_ORDER) > 0) {
                    
                    foreach ($matches as $match) {
                        if(in_array(trim($match[1]), $EAttribs)){} else {
                           
                        $a = trim($match[1]);
                        global $$ArrayName;
						
                        // sanitize attribute value: remove surrounding quotes, convert literal \n to <br>, strip backslashes
                        $match[2]=str_replace('"','',$match[2]);
                        $match[2]=str_replace('\\n','<br>',$match[2]);
                       $match[2]=str_replace('\\','',$match[2]);
                        // Remove single quotes (already expected), and replace backticks which break generated JS template literals
                        $match[2]=str_replace("'", "", $match[2]);
                        $match[2]=str_replace('`', '&#96;', $match[2]);
                        // remove stray CR characters
                        $match[2]=str_replace("\r", "", $match[2]);
                        ${trim($ArrayName)}[$a][$ClassID] = $match[2];
                        unset ($match);
				
                    }
                }
            }}
        }
        fclose($objector);

    }
}

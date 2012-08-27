#!/usr/bin/env php
<?php
//in progress, use at your own risk
if (!defined('DS')) define('DS','/');
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
date_default_timezone_set('America/Los_Angeles');

require_once dirname(__FILE__) . '/'. 'vendor/magento/downloader/lib/Mage/Archive/Helper/File.php';
require_once dirname(__FILE__) . '/'. 'vendor/magento/downloader/lib/Mage/Archive/Interface.php';
require_once dirname(__FILE__) . '/'. 'vendor/magento/downloader/lib/Mage/Archive/Abstract.php';
require_once dirname(__FILE__) . '/'. 'vendor/magento/downloader/lib/Mage/Archive/Tar.php';
require_once dirname(__FILE__) . '/'. 'vendor/magento/downloader/lib/Mage/Exception.php';

//from http://php.net/glob
if ( ! function_exists('glob_recursive'))
{
    // Does not support flag GLOB_BRACE    
    function glob_recursive($pattern, $flags = 0)
    {
        $files = glob($pattern, $flags);        
        foreach (glob(dirname($pattern).'/*', GLOB_ONLYDIR|GLOB_NOSORT) as $dir)
        {
            $files = array_merge($files, glob_recursive($dir.'/'.basename($pattern), $flags));
        }        
        return $files;
    }
}

function input($string)
{
    echo $string . "\n] ";
    $handle = fopen ("php://stdin","r");
    $line = fgets($handle);        
    fclose($handle);
    return $line;
}

function error($string)
{
    echo "ERROR: " . $string . "\n";
    exit;
}


function create_package_xml_add_node($xml, $full_dir, $base_dir=false)
{
    $parts = explode("/",str_replace($base_dir.'/','',$full_dir));        
    $single_file  = array_pop($parts);
    $node = $xml;                
    foreach($parts as $part)
    {
        $nodes = $node->xpath("dir[@name='".$part."']");
        if(count($nodes) > 0)
        {
            $node = array_pop($nodes);
        }
        else
        {
            $node = $node->addChild('dir');
            $node->addAttribute('name', $part);
        }                                    
    }    
    
    $node = $node->addChild('file');
    $node->addAttribute('name',$single_file);
    $node->addAttribute('hash',md5_file($full_dir));
}
        
function create_package_xml($files, $base_dir, $config)
{
    $xml = simplexml_load_string('<package/>');    
    $xml->name          = $config['extension_name'];
    $xml->version       = $config['extension_version'];
    $xml->stability     = $config['stability'];
    $xml->license       = $config['license'];
    $xml->channel       = $config['channel'];
    $xml->extends       = '';
    $xml->summary       = $config['summary'];
    $xml->description   = $config['description'];
    $xml->notes         = $config['notes'];
    
    $authors            = $xml->addChild('authors');
    $author             = $authors->addChild('author');
    $author->name       = $config['author_name'];
    $author->user       = $config['author_user'];
    $author->email      = $config['author_email'];
    
    $xml->date          = date('Y-m-d');
    $xml->time          = date('G:i:s');
    $xml->compatible    = '';
    $dependencies       = $xml->addChild('dependencies');
    $required           = $dependencies->addChild('required');
    $php                = $required->addChild('php');
    $php->min           = $config['php_min'];   //'5.2.0';
    $php->max           = $config['php_max'];   //'6.0.0';
    
    $node = $xml->addChild('contents');		
    $node = $node->addChild('target');
    $node->addAttribute('name', 'mage');
    
    //     $files = $this->recursiveGlob($temp_dir);
    //     $files = array_unique($files);              
    $temp_dir = false;
    foreach($files as $file)
    {
        //$this->addFileNode($node,$temp_dir,$file);
        create_package_xml_add_node($node, $file, $base_dir);
    }                
    //file_put_contents($temp_dir . '/package.xml', $xml->asXml());            
    
    return $xml->asXml();
}

function get_temp_dir()
{
    $name = tempnam(sys_get_temp_dir(),'tmp');
    unlink($name);
    $name = $name;			
    mkdir($name,0777,true);
    return $name;
}

function validate_config($config)
{
    $keys = array('base_dir','archive_files','path_output',
    );
    foreach($keys as $key)
    {
        if(!array_key_exists($key, $config))
        {
            error("Config file missing key [$key]");
        }
    }
    
    if($config['author_email'] == 'foo@example.com')
    {
        $email = input("Email Address is configured with foo@example.com.  Enter a new address");
        if(trim($email) != '')
        {
            $config['author_email'] = trim($email);
        }
    }
    return $config;
    
    
}

function load_config($config_name=false)
{
    if(!$config_name)
    {
        $config_name = basename(__FILE__,'php') . 'config.php';
    }
    if(!file_exists($config_name))
    {
        error("Could not find $config_name");
    }
    $config = include $config_name;
    $config = validate_config($config);
    return $config;
}

function main($argv)
{
    $this_script = array_shift($argv);
    $config_file = array_shift($argv);    
    $config = load_config($config_file);
    
    $base_dir           = $config['base_dir'];          //'/Users/alanstorm/Documents/github/Pulsestorm/var/build';
    $archive_files      = $config['archive_files'];     //'Pulsestorm_Modulelist.tar';    
    $path_output        = $config['path_output'];       //'/Users/alanstorm/Desktop/working';    
    $archive_connect    = $config['extension_name'] . '-' . $config['extension_version'] . '.tgz';
    
    $temp_dir   = get_temp_dir();        
    chdir($temp_dir);
    shell_exec('cp '        . $base_dir . '/' . $archive_files . ' ' . $temp_dir);
    shell_exec('tar -xvf '  . $temp_dir . '/' . $archive_files);
    shell_exec('rm '        . $temp_dir . '/' . $archive_files);
    
    $all        = glob_recursive($temp_dir  . '/*');
    $dirs       = glob_recursive($temp_dir .'/*',GLOB_ONLYDIR);
    $files      = array_diff($all, $dirs);
    $xml        = create_package_xml($files,$temp_dir,$config);
    
    file_put_contents($temp_dir . '/package.xml',$xml);    
    echo $temp_dir,"\n";
    
    if(!is_dir($path_output))
    {
        mkdir($path_output, 0777, true);
    }
    
    $archiver = new Mage_Archive_Tar;
    $archiver->pack($temp_dir,$path_output.'/'.$archive_files,true);
    
    shell_exec('gzip '  . $path_output . '/' . $archive_files);
    shell_exec('mv '    . $path_output . '/' . $archive_files.'.gz '.$path_output.'/' . $archive_connect);
    #echo $xml;
    #echo "\nDone\n";
    echo "Built in $path_output\n";
}
main($argv);
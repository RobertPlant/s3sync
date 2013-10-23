#!/usr/bin/php
<?php

//Email recipient to send status email to after sync.
define("MAIL_TO_RECIP", "");

require('vendor/autoload.php');

use Aws\S3\S3Client;

class S3sync
{
    private $_s3;
    private $_startTime;
    private $_bucketName = '';
    private $_directory = '';
    private $_fileList = array();
    private $_fileHashList = array();
    private $_userBuckets = array();
    private $_filesUploaded = 0;
    private $_filesAlreadyUploaded = 0;
    private $_uploadErrors = 0;
    private $_s3Objects = array();
    private $_errorMessages = array();
    private $isDryRun = FALSE;
    const MAX_FILE_SIZE_IN_BYTES = 4294967296;
    protected $_blacklist = array();
    protected $_ignoredFiles;
    protected $_remotePath;

    public function __construct($bucketName, $directory, $dryRun = FALSE)
    {
        ini_set('memory_limit', '2048M');
        $this->_s3 = S3Client::factory(
            array(
                'key' => "",
                'secret' => ""
            )
        );
        $this->_blacklist = array(
            'xml', 'txt', 'edi'
        );
        $this->_remotePath = '';

        $this->_s3->setSslVerification(true);
        $this->_startTime = microtime(true);
        $this->_bucketName = $bucketName;
        $this->_directory = $directory;
        $this->isDryRun = $dryRun;

        if (empty($bucketName) || empty($directory)) {
            throw new Exception('Missing bucket or directory');
        } else {
            echo "\n\nInitiated sync service with bucket {$this->_bucketName}\n";
        }

        $this->loadUsersBuckets();
        $this->checkBucketIsValid();
        if ($this->isDryRun) {
            echo "WARNING: YOU ARE RUNNING IN DRY RUN MODE, NO FILES WILL BE UPLOADED TO S3.\n\n";
        }
        //Retreive a list of files to be processed
        $this->_fileList = $this->getFileListFromDirectory($this->_directory);
        $totalFileCount = count($this->_fileList) + $this->_ignoredFiles;

        if ($this->_fileList === FALSE) {
            throw new Exception("Unable to get file list from directory.");
        } else {
            echo "\n\nTotal number of files found to process $totalFileCount \n";
        }
    }

    public function sync()
    {
        $this->loadObjectsFromS3Bucket();

        echo "Begining to upload....\n\n";

        foreach ($this->_fileList as $fileMeta) {
            if (!isset($this->_s3Objects[ltrim($this->_remotePath, '/') . ltrim($fileMeta['path'], '/')])) {
                echo "|";
                $this->uploadFile($fileMeta);
            } else {
                echo "-";
                $this->_filesAlreadyUploaded++;
            }
        }
        echo "\n";
    }


    public function uploadFile($fileMeta)
    {
        $fullPath = $fileMeta['path'];

        if ($this->_filesUploaded % 100 == 0 && $this->_filesUploaded != 0) {
            echo "({$this->_filesUploaded} / " . count($this->_fileList) . ") \n";
        }

        if (!$this->isDryRun) {
            $response = $this->_s3->putObject(
                array(
                    'Bucket' => $this->_bucketName,
                    'Key' => $this->_remotePath . $fullPath,
                    'Body' => fopen($fullPath, 'r')
                )
            );
            if (is_object($response)) {
                $this->_filesUploaded++;
            } else {
                $this->_uploadErrors++;
            }
        } else {
            $this->_filesUploaded++;
        }
    }


    public function __destruct()
    {
        $endTime = microtime(TRUE);
        $totalTime = $endTime - $this->_startTime;

        $out = array();
        $out[] = "************************* RESULTS ***********************\n ";
        $out[] = "Total time: $totalTime (s)\n";
        $out[] = "Total files examined: " . (count($this->_fileList) + $this->_ignoredFiles) . "\n";
        $out[] = "Total files uploaded to S3: {$this->_filesUploaded}\n";
        $out[] = "Total files ignored (already in s3): {$this->_filesAlreadyUploaded}\n";
        $out[] = "Total files ignored (file extension blacklist): {$this->_ignoredFiles}\n";
        $out[] = "Total upload errors: {$this->_uploadErrors}\n";
        $out[] = "***********************************************************\n ";

        if (count($this->_errorMessages) > 0) {
            $out[] = implode("\n", $this->_errorMessages);
        }

        $message = implode("\n", $out);
        echo $message;

        mail(MAIL_TO_RECIP, "S3 Sync Results", $message);
    }

    private function checkBucketIsValid()
    {
        //Make sure user specified a valid bucket.
        if (!isset($this->_userBuckets[$this->_bucketName])) {
            echo "\nUnable to find the bucket specified in your bucket list.  Did you mean one of the following?\n\n";
            foreach ($this->_userBuckets as $v) {
                echo "\t" . $v['Name'] . "\n";
            }
            echo "\n";
            exit;
        }
    }


    public function loadObjectsFromS3Bucket()
    {
        $results = array();
        $iterator = $this->_s3->getIterator('ListObjects', array(
            'Bucket' => $this->_bucketName
        ));

        foreach ($iterator->toArray() as $object) {
            $results[$object['Key']] = $object['Key'];
        }

        $this->_s3Objects = $results;
    }

    public function loadUsersBuckets()
    {
        $response = $this->_s3->listBuckets()->toArray();

        if (!is_array($response['Buckets'])) {
            throw new Exception("Unable to retrieve users buckets");
        }

        foreach ($response['Buckets'] as $bucket) {
            $tmpName = (string)$bucket['Name'];
            $results[$tmpName] = $tmpName;
        }

        $this->_userBuckets = $results;
    }

    public function getFileListFromDirectory($dir)
    {
        $retval = array();

        if (substr($dir, -1) != "/") {
            $dir .= "/";
        }

        $d = @dir($dir);

        if ($d === FALSE) {
            return FALSE;
        }

        while (false !== ($entry = $d->read())) {

            if ($entry[0] == ".") {
                continue;
            }

            if (is_dir("$dir$entry")) {
                if (is_readable("$dir$entry/")) {
                    $retval = array_merge($retval, $this->getFileListFromDirectory("$dir$entry/", true));
                }
            } elseif (is_readable("$dir$entry")) {
                $tFileName = "$dir$entry";
                $pathInfo = pathinfo($tFileName);
                if (in_array($pathInfo['extension'], $this->_blacklist)) {
                    $this->_ignoredFiles++;
                    continue;
                }
                $hash = md5($tFileName);
                $size = filesize($tFileName);
                if ($size > self::MAX_FILE_SIZE_IN_BYTES) {
                    $this->_errorMessages[] = "The following file will not be processed as it exceeds the max file size: $tFileName";
                    continue;
                } else {
                    $retval[$hash] = array('path' => $tFileName, 'file' => $entry, 'hash' => $hash);
                    if (isset($this->_fileHashList[$hash])) {
                        $this->_errorMessages[] = "WARNING: FOUND A HASH COLLISSION, DUPLICATE FILE:$tFileName ";
                    } else {
                        $this->_fileHashList[$hash] = 1;
                    }
                }
            }
        }
        $d->close();

        return $retval;
    }
}

if ($argc != 3 && $argc != 4) {
    echo "\nSync all files in a directory against an s3 instance.\n\n";
    echo "Usage: bucketName directoryName (dryrun - optional)\n\n";
    die();
}

$bucketName = $argv[1];
$directoryName = $argv[2];
$dryRun = isset($argv[3]) ? $argv[3] : FALSE;

$s = new S3sync($bucketName, $directoryName, $dryRun);
$s->sync();
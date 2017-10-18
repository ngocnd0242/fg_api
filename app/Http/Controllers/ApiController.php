<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use File;
use Illuminate\Support\Facades\Storage;
use Exception;


class ApiController extends Controller
{
    private $mode = 0777;
    const DOWNLOAD_FOLDER = 'app/public/';

    public function makeComponents(Request $request)
    {
        $url = $request->get('asset_url');

        try {
            $path = str_replace(env('BASE_URL'), "", $url);
            $partialPath = explode("/", $path);

            $this->makeDirectory($partialPath[1] . '/' . $partialPath[2]);

            $s3 = app()->make('aws')->createClient('s3');


            if (Storage::disk('s3')->exists($path)) {
                $s3->getObject([
                    'Bucket' => env('AWS_BUCKET'),
                    'Key' => $path,
                    'SaveAs' => storage_path(self::DOWNLOAD_FOLDER . $path),
                ]);

                $filePath = 'assets/' . substr(md5(time()), 0, 7) . '.json';
                $s3->putObject(array(
                    'Bucket' => env('AWS_BUCKET'),
                    'Key' => $filePath,
                    'SourceFile' => storage_path(self::DOWNLOAD_FOLDER . $path),
                ));
            }

            return response()->json([env('BASE_URL') . $filePath], \Illuminate\Http\Response::HTTP_OK);
        } catch (Exception $e) {
            $res = [
                'message' => $e->getMessage(),
                'data' => []
            ];
            return response()->json($res, \Illuminate\Http\Response::HTTP_BAD_REQUEST);
        }
    }

    private function makeDirectory($folderPath)
    {
        $folderPath = storage_path(self::DOWNLOAD_FOLDER . $folderPath);
        if (!File::isDirectory($folderPath)) {
            $folderPath = File::makeDirectory($folderPath, $this->mode, true);
        }
        return $folderPath;
    }
}

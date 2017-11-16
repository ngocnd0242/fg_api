<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use File;
use Illuminate\Support\Facades\Storage;
use Exception;
use \Illuminate\Http\Response;

class ApiController extends Controller
{
    private $mode = 0777;
    const DOWNLOAD_FOLDER = 'app/public/';

    protected $jsonData = [
        "type" => "components_description",
        "version" => 1,
        "id" => "1235346",
        "tag" => "asset-1351235-r-20171025111345",
        "components" => [
            0 => [
                "name" => "iOS",
                "version" => "10.1.2",
            ],
            1 => [
                "name" => "Android",
                "version" => "6.5.0",
            ]
        ]
    ];

    public function makeComponents(Request $request)
    {
        $data = $request->all();
        try {
            if (!isset($data['asset_id'])) {
                throw new Exception('asset_id not exists');
            }
            $path = str_replace(env('BASE_URL'), '', $data['package_url']);
            $partialPath = explode("/", $path);
            $this->makeDirectory($partialPath[0] . '/' . $partialPath[1]);
            $s3 = app()->make('aws')->createClient('s3');
            if (Storage::disk('s3')->exists($path)) {
                $filePath = 'assets/' . substr(md5(time()), 0, 7) . '.json';

                $this->jsonData['tag'] = $data['tag'];
                File::put(storage_path(self::DOWNLOAD_FOLDER . $path), json_encode($this->jsonData));

                // upload to asset folder in s3
                $s3->putObject(array(
                    'Bucket' => env('AWS_BUCKET'),
                    'Key' => $filePath,
                    'SourceFile' => storage_path(self::DOWNLOAD_FOLDER . $path),
                ));

                return response()->json([env('BASE_URL') . $filePath], Response::HTTP_OK);
            }
        } catch (Exception $e) {
            $res = [
                'message' => $e->getMessage(),
                'data' => []
            ];

            return response()->json($res, Response::HTTP_BAD_REQUEST);
        }

        return response()->json(['Some thing went wrong!'], Response::HTTP_BAD_REQUEST);
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

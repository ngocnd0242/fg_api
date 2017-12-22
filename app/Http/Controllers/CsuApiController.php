<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Exception;
use File;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CsuApiController extends Controller
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

    /**
     * api register target
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function registerTarget(Request $request)
    {
        $data = $request->all();
        $data = $data[0];
        try {
            $path = str_replace(env('REMOVE_PATH'), '', $data['package_url']);
            $partialPath = explode("/", $path);
            $this->makeDirectory('packages' . '/' . $partialPath[1]);
            $this->makeDirectory('assets' . '/' . $partialPath[1]);
            $s3 = app()->make('aws')->createClient('s3');

            if (Storage::disk('s3')->exists($path)) {
                // download file from aws to local
                $s3->getObject([
                    'Bucket' => env('AWS_BUCKET'),
                    'Key' => $path,
                    'SaveAs' => storage_path(self::DOWNLOAD_FOLDER . $path),
                ]);

                // read and convert file
                $dataPackages = json_decode(file_get_contents(storage_path(self::DOWNLOAD_FOLDER . $path)));
                $packageInstalls = $this->decryptData($dataPackages->packages_installed);
                $dataComponents = [];
                foreach ($packageInstalls as $key => $packageInstall) {
                    $dataComponents[$key]['name'] = $packageInstall->n;
                    $dataComponents[$key]['version'] = $packageInstall->v;
                }

                $this->jsonData['id'] = $partialPath[1];
                $this->jsonData['components'] = $dataComponents;
                $this->jsonData['tag'] = $data['tag'];

                // save file
                $filePath = 'assets/' . substr(md5(time()), 0, 7) . '.json';
                File::put(storage_path(self::DOWNLOAD_FOLDER . $filePath), json_encode($this->jsonData));

                // upload to asset folder in s3
                $s3->putObject([
                    'Bucket' => env('AWS_BUCKET'),
                    'Key' => $filePath,
                    'SourceFile' => storage_path(self::DOWNLOAD_FOLDER . $filePath),
                ]);

                $reData = [
                    'tag' => $this->jsonData['tag'],
                    'id' => $this->jsonData['id'],
                    'url' => env('BASE_URL') . $filePath,
                ];

                return response()->json([$reData], Response::HTTP_CREATED);
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

    /**
     * api scan white box
     * @param Request $request
     */
    public function scanWhiteBox(Request $request)
    {
        try {
            $targetIds = $request->all();
            $diagnoseData = [
                'target_id' => $targetIds[0],
                'version' => 1,
                'analysis_id' => rand(1, 100),
                'type' => 'recon'
            ];
            $s3 = app()->make('aws')->createClient('s3');
            foreach ($targetIds as $targetId) {
                $configurations = DB::table('configurations')->where('target_id', $targetId)->first();
                $this->makeDirectory('diagnoses' . '/' . $configurations->asset_id);
                if ($configurations) {
                    $diagnoseData['timestamp'] = Carbon::parse($configurations->user_uploaded_at)->timestamp;
                    //$componentData = $this->getCompoData($configurations->components_data_url);
                    $diagnoseData['detected'] = $this->getDetectedData();
                    $diagnoseData['whitebox']['content'] = $this->getWhiteBoxData();

                    // save file
                    $filePath = "diagnoses/$configurations->asset_id/" . substr(md5(time()), 0, 7) . '.json';
                    File::put(storage_path(self::DOWNLOAD_FOLDER . $filePath), json_encode($diagnoseData));

                    // upload to asset folder in s3
                    $s3->putObject(array(
                        'Bucket' => env('AWS_BUCKET'),
                        'Key' => $filePath,
                        'SourceFile' => storage_path(self::DOWNLOAD_FOLDER . $filePath),
                    ));

                    $resData = [
                        'analysis_id' => $diagnoseData['analysis_id'],
                        'location' => "/analysis/recon/" . $targetIds[0] . "/" . $diagnoseData['analysis_id'],
                        'url' => env('BASE_URL') . $filePath,
                    ];

                    return response()->json([$resData], Response::HTTP_CREATED);
                }
            }
        } catch (Exception $e) {
            return \response()->json($e->getMessage(), $e->getCode());
        }

        return \response()->json('target not exists', Response::HTTP_BAD_REQUEST);
    }

    /**
     * random detected data
     * @return array
     */
    public function getDetectedData()
    {
        $detectedDataRand = [
            [
                'confidence' => 'tentative',
                'cvss' => 1.0
            ], [
                'confidence' => 'firm',
                'cvss' => 5.0
            ], [
                'confidence' => 'certain',
                'cvss' => 9.0
            ]
        ];

        $detectedData = [];
        for ($i = 0; $i <= 49; $i++) {
            $detectedData[$i] = array_random($detectedDataRand);
            $detectedData[$i]['id'] = ($i + 1);
        }

        return $detectedData;
    }

    /**
     * get white box data
     * @return array
     */
    public function getWhiteBoxData()
    {
        $whiteBox = [];
        $cvedata = DB::table('jvns')->inRandomOrder()->limit(50)->get();
        foreach ($cvedata as $key => $jvns) {
            $whiteBox[$key]['id'] = ($key + 1);
            $whiteBox[$key]['cvss'] = $jvns->score;
            $whiteBox[$key]['cve'] = $jvns->cve_id;
            $whiteBox[$key]['summary'] = $jvns->summary;
            $whiteBox[$key]['affected'] = 'bash-4.1.2-40.el6';
        }

        return $whiteBox;
    }

    public function getCompoData($componentsDataUrl)
    {
        if (File::exists(storage_path(self::DOWNLOAD_FOLDER . $componentsDataUrl))) {
            $componentData = json_decode(file_get_contents(storage_path(self::DOWNLOAD_FOLDER . $componentsDataUrl)));
        } else {
            // download file from aws to local
            $s3 = app()->make('aws')->createClient('s3');
            $s3->getObject([
                'Bucket' => env('AWS_BUCKET'),
                'Key' => $componentsDataUrl,
                'SaveAs' => storage_path(self::DOWNLOAD_FOLDER . $componentsDataUrl),
            ]);

            $componentData = json_decode(file_get_contents(storage_path(self::DOWNLOAD_FOLDER . $componentsDataUrl)));
        }

        return $componentData;
    }

    private function makeDirectory($folderPath)
    {
        $folderPath = storage_path(self::DOWNLOAD_FOLDER . $folderPath);
        if (!File::isDirectory($folderPath)) {
            $folderPath = File::makeDirectory($folderPath, $this->mode, true);
        }

        return $folderPath;
    }

    private function decryptData($data)
    {
        return json_decode(zlib_decode(base64_decode($data)));
    }
}

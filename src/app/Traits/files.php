<?php

namespace PatrykSawicki\Helper\app\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\ImageManager;

/*
 * Trait for saving files.
 * */
trait files
{
    /**
     * Add file.
     *
     * @param UploadedFile $file
     * @param string $location e.g. order_files
     * @param string $relationName e.g. files
     * @param int|null $max_width
     * @param int|null $max_height
     * @param bool $externalRelation
     * @param bool $forceWebP
     * @param bool $preventResizing
     * @param array $options
     * @return Model
     */
    public function addFile(UploadedFile $file, string $location='files', string $relationName='files', int
    $max_width=null, int $max_height=null, bool $externalRelation = true, bool $forceWebP = true, bool $preventResizing = false, array $options=[]): Model
    {
        if(strtolower(config('filesystems.default')) == 's3')
            $options = [];

        $fileName = $file->getClientOriginalName();
        $filePath = '/'.config('filesSettings.main_dir', 'hidden').'/' . $location . '/' .
                  date('Y').'/'.date('m').'/'.date('d').'/';
        $extension = explode('.', $fileName);
        $extension = strtolower($extension[count($extension)-1]);

        if($forceWebP && (config('filesSettings.block_webp_conversion') || in_array($extension, config('filesSettings.forbidden_webp_extensions'))))
            $forceWebP = false;

        $fileModel = $this->{$relationName}()->create([
            'name' => $fileName,
            'type' => $extension,
            'mime_type' => $file->getMimeType(),
            'file' => $filePath,
        ]);

        if(!$externalRelation)
            $this->update([
                $this->{$relationName}()->getForeignKeyName() => $fileModel->id,
            ]);

        $fileModel->update(['file'=>$filePath.$fileModel->id,]);

        if(explode('/', $file->getMimeType())[0]=='image' && !str_contains($file->getMimeType(), 'svg'))
        {
            $max_width ??= config('filesSettings.images.max_width', 1280);
            $max_height ??= config('filesSettings.images.max_height', 720);
            [$w, $h]=getimagesize($file->getRealPath());

            if((!$preventResizing && ($w > $max_width || $h > $max_height)) || ($forceWebP && $extension != 'webp'))
            {
//                $image = ImageManager::make($file);
                $manager = new ImageManager(new Driver());
                $image = $manager->read($file);

                if(!$preventResizing)
                    $image->scale(width: $max_width, height: $max_height);

                $format = null;
                if($forceWebP)
                {
                    $format = new WebpEncoder();
                    $fileModel->update([
                        'name' => str_replace('.'.$extension, '.webp', $fileName),
                        'type' => 'webp',
                        'mime_type' => 'image/webp',
                    ]);
                }

                Storage::put(
                    $filePath.$fileModel->id,
                    (string) $image->encode($format),
                    $options
                );

                $fileModel->update([
                    'width' => $image->width(),
                    'height' => $image->height(),
                ]);

                return $fileModel;
            }
            else
                $fileModel->update([
                    'width' => $w,
                    'height' => $h,
                ]);
        }

        Storage::putFileAs(
            $filePath,
            $file,
            $fileModel->id,
            $options
        );
        return $fileModel;
    }

    /**
     * Add files.
     *
     * @param array $files
     * @param string $location e.g. order_files
     * @param string $relationName e.g. files
     */
    public function addFiles(array $files, string $location='files', string $relationName='files')
    {
        foreach($files as $file)
            $this->addFile($file, $location, $relationName);
    }
}

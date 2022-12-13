<?php

namespace PatrykSawicki\Helper\app\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\ImageManagerStatic;

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
     * @return Model
     */
    public function addFile(UploadedFile $file, string $location='files', string $relationName='files', int $max_width=null, int $max_height=null, bool $externalRelation = true, bool $forceWebP = true): Model
    {
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

        if(explode('/', $file->getMimeType())[0]=='image')
        {
            $max_width??=config('filesSettings.images.max_width', 1280);
            $max_height??=config('filesSettings.images.max_height', 720);
            [$w, $h]=getimagesize($file->getRealPath());

            if($w > $max_width || $h > $max_height || ($forceWebP && $extension != 'webp'))
            {
                $image = ImageManagerStatic::make($file);
                $image->resize($max_width, $max_height, function ($constraint) {
                    $constraint->aspectRatio();
                });

                $format = null;
                if($forceWebP)
                {
                    $format = 'webp';
                    $fileModel->update([
                        'name' => str_replace('.'.$extension, '.webp', $fileName),
                        'type' => $format,
                        'mime_type' => 'image/webp',
                    ]);
                }

                Storage::put($filePath.$fileModel->id, (string) $image->encode($format));

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

        $file->storeAs($filePath, $fileModel->id);
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

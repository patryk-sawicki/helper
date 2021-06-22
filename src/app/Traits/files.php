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
     * @return Model
     */
    public function addFile(UploadedFile $file, string $location='files', string $relationName='files'): Model
    {
        $fileName=$file->getClientOriginalName();
        $filePath='/'.config('filesSettings.main_dir', 'hidden').'/' . $location . '/' .
                  date('Y').'/'.date('m').'/'.date('d').'/';
        $extension=explode('.', $fileName);
        $extension=strtolower($extension[count($extension)-1]);

        $fileModel=$this->{$relationName}()->create([
            'name'=>$fileName,
            'type'=>$extension,
            'file'=>$filePath,
        ]);

        if(explode('/', $file->getMimeType())[0]=='image')
        {
            $max_width=config('filesSettings.images.max_width', 1280);
            $max_height=config('filesSettings.images.max_height', 720);
            [$w, $h]=getimagesize($file->getRealPath());

            if($w > $max_width || $h > $max_height)
            {
                $image = ImageManagerStatic::make($file);
                $image->resize($max_width, $max_height, function ($constraint) {
                    $constraint->aspectRatio();
                });
                Storage::put($filePath.$fileModel->id, (string) $image->encode());
                return $fileModel;
            }
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

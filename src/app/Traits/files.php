<?php

namespace PatrykSawicki\Helper\Traits;

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
     * @param string $type e.g. order_files
     */
    public function addFile(UploadedFile $file, string $type='files')
    {
        $fileName=$file->getClientOriginalName();
        $filePath='/'.config('filesSettings.main_dir', 'hidden').'/'.$type.'/'.
                  date('Y').'/'.date('m').'/'.date('d').'/';
        $extension=explode('.', $fileName);
        $extension=strtolower($extension[count($extension)-1]);

        $fileModel=$this->files()->create([
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
                return;
            }
        }

        $file->storeAs($filePath, $fileModel->id);
    }

    /**
     * Add files.
     *
     * @param array $files
     * @param string $type e.g. order_files
     */
    public function addFiles(array $files, string $type='files')
    {
        foreach($files as $file)
            $this->addFile($file, $type);
    }
}

<?php

namespace PatrykSawicki\Helper\app\Traits;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use PatrykSawicki\Helper\app\Traits\files;

trait uploads
{
    /**
     * Add image.
     *
     * @param UploadedFile $uploadedFile
     * @param string $location e.g. order_files
     * @param string $relationName e.g. files
     * @param array $categories
     * @param int|null $max_width
     * @param int|null $max_height
     * @param bool $externalRelation
     * @return Model
     */
    public function addUpload(UploadedFile $uploadedFile, string $location='uploads', string $relationName='files', array $categories=[], int $max_width=null, int $max_height=null, bool $externalRelation = true): Model
    {
        if(explode('/', $uploadedFile->getMimeType())[0] != 'image' ||
           str_contains($uploadedFile->getMimeType(), 'svg'))
        {
            $file=$this->addFile($uploadedFile, $location, $relationName, $max_width, $max_height, $externalRelation);

            if(!empty($categories))
                $file->categories()->sync($categories);

            return $file;
        }

        $file=$this->addFile($uploadedFile, $location, $relationName, $max_width, $max_height, $externalRelation);

        if(!empty($categories))
            $file->categories()->sync($categories);

        foreach(config('filesSettings.thumbnailSizes', []) as $thumbnailSize)
            $file->addFile($uploadedFile, $location, 'thumbnails', $thumbnailSize['width'], $thumbnailSize['height']);

        return $file;
    }

    /**
     * Add uploads.
     *
     * @param array $files
     * @param string $location e.g. order_files
     * @param string $relationName e.g. files
     * @param array $categories
     */
    public function addUploads(array $files, string $location='uploads', string $relationName='files', array $categories=[]): void
    {
        foreach($files as $file)
            $this->addUpload($file, $location, $relationName, $categories);
    }
}

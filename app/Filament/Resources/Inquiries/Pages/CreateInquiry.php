<?php

namespace App\Filament\Resources\Inquiries\Pages;

use App\Filament\Concerns\BoxedPage;
use App\Filament\Resources\Inquiries\InquiryResource;
use Filament\Resources\Pages\CreateRecord;

class CreateInquiry extends CreateRecord
{
    use BoxedPage;

    protected static string $resource = InquiryResource::class;

    protected function getRedirectUrl(): string
    {
        return InquiryResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}

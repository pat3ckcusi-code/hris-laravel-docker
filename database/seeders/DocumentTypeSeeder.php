<?php

namespace Database\Seeders;

use App\Models\DocumentType;
use Illuminate\Database\Seeder;

class DocumentTypeSeeder extends Seeder
{
    public function run(): void
    {
        $certificateBody = <<<'TEXT'
This is to certify that {employee_name} is an employee of the City Government of Calapan. He/She is currently holding the position assigned at the City Human Resource Management Department.

This certification is issued upon the request of {employee_name} for whatever legal purpose it may serve.
TEXT;

        DocumentType::firstOrCreate(
            ['name' => 'Certificate of Employment'],
            [
                'parts' => [
                    'title'                => 'CERTIFICATE OF EMPLOYMENT',
                    'salutation'           => 'To Whom It May Concern:',
                    'header'               => 'CITY GOVERNMENT OF CALAPAN' . "\n" . 'City Human Resource Management Department',
                    'body'                 => $certificateBody,
                    'closing_remark'       => 'Given this {date} in Calapan City upon the request of {employee_name} for whatever legal purpose it may serve.',
                    'signatories'          => 'MARIAN TERESA G. TAGUPA LPT, MBA, JD' . "\n" . 'OFFICER-IN-CHARGE',
                    'authorized_signature' => 'MARIAN TERESA G. TAGUPA LPT, MBA, JD',
                    'footer'               => 'Not Valid Without Seal.' . "\n" . 'O.R. No:' . "\n" . 'Date:' . "\n" . 'Prepared by:' . "\n" . 'Verified by:',
                ],
            ]
        );
    }
}

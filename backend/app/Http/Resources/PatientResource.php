<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PatientResource extends JsonResource
{
    /**
     * RN-10: El rol auditor ve los datos del paciente enmascarados.
     */
    public function toArray(Request $request): array
    {
        $isAuditor = $request->user()?->role === 'auditor';

        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'document_number' => $isAuditor ? $this->maskDocument($this->document_number) : $this->document_number,
            'full_name' => $isAuditor ? $this->maskName($this->full_name) : $this->full_name,
            'birth_date' => $isAuditor ? '****-**-**' : $this->birth_date?->toDateString(),
            'phone' => $isAuditor ? '*******'.substr((string) $this->phone, -3) : $this->phone,
            'is_masked' => $isAuditor,
        ];
    }

    private function maskDocument(string $doc): string
    {
        $visible = substr($doc, -4);

        return str_repeat('*', max(strlen($doc) - 4, 4)).$visible;
    }

    private function maskName(string $name): string
    {
        return collect(explode(' ', trim($name)))
            ->filter()
            ->map(fn (string $part) => mb_substr($part, 0, 1).str_repeat('*', max(mb_strlen($part) - 1, 3)))
            ->implode(' ');
    }
}

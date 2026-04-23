<?php

namespace App\Components\PromptAssembler;

use App\Components\RequestPipeline\DTO\PromptBlock;

class DataBlockFormatter
{
    private int $autoIdCounter = 0;

    public function format(PromptBlock $dataBlock, ?PromptBlock $description): string
    {
        $result = '';

        if ($description) {
            $result .= $description->content . "\n";
        }

        $id = $dataBlock->id ?? $this->generateAutoId();
        $labelAttr = $dataBlock->label ? " label=\"{$dataBlock->label}\"" : '';

        $result .= "<{$id}{$labelAttr}>\n";
        $result .= $dataBlock->content . "\n";
        $result .= "</{$id}>";

        return $result;
    }

    private function generateAutoId(): string
    {
        $this->autoIdCounter++;

        return "data_{$this->autoIdCounter}";
    }
}

<?php

namespace Ptrufanov1\XmlStreamParser;

use Illuminate\Support\Collection;
use Trufanov\Exception\XmlStreamParser\IncompleteParseException;
use XMLReader;

class XmlStreamParser {

    protected string $source;
    protected XMLReader $reader;
    private int $skipElements = 1;
    private int $skipDepth = 0;

    public function __construct(string $source, XMLReader $reader) {
        $this->reader = $reader;
        $this->source = $source;
    }

    public static function from(string $source): self {
        $reader = new XMLReader();
        $reader->open($source);
        return new self($source, $reader);
    }

    /**
     * @throws IncompleteParseException
     */
    public function toCollect(): Collection {
        $collect = collect();
        $this->each(function ($element) use ($collect) {
            $collect->push($element);
        });
        return $collect;
    }

    /**
     * @throws IncompleteParseException
     */
    public function each(callable $function): void {
        while ($this->reader->read()) {
            $this->searchElement($function);
        }
        $this->close();
    }

    public function withSkipElements(int $n = 1): self {
        $this->skipElements = $n;
        return $this;
    }

    public function withSkipFirstElement(): self {
        $this->withSkipElements();
        return $this;
    }

    public function withSkipDepths(int $n = 1): self {
        $this->skipDepth = $n;
        return $this;
    }

    public function withSkipFirstDepth(): self {
        $this->withSkipDepths();
        return $this;
    }

    private function searchElement(callable $function): void {
        if ($this->isElement() && !$this->shouldBeSkipped()) {
            $function($this->extractElement(), $this->reader->name);
        }
    }

    private function insureValue($value): string {
        return htmlspecialchars(trim($value));
    }

    private function extractElement(?int $parentDepth = 1, ?string $parentElement = null, ?array $prevElementData = null): array {

        $elementName = $this->reader->name;
        $elementData[$elementName] = $this->getCurrentElementAttributes();

        if ($this->reader->isEmptyElement) {
            $elementData[$elementName]['value'] = null;
            $elementData[$elementName]['name'] = $elementName;

            return $elementData;
        }

        $resideCount = 0;
        while ($this->reader->read()) {

            if ($this->isWhitespace()) {
                continue;
            }

            if ($this->isEndElement() && $this->equaledDepth($parentDepth)) {
                break;
            }

            if ($this->isValue()) {

                $elementData[$elementName]['value'] = $this->reader->value;
                $elementData[$elementName]['name'] = $elementName;

                return $elementData;
            }

            if ($this->isElement()) {
                $foundElementName = $this->reader->name;

                $keyName = $parentElement ?? $elementName;
                $checkData = $prevElementData ?? $elementData;

                $extract = $this->extractElement($this->reader->depth, $foundElementName, $elementData);

                if (array_key_exists($foundElementName, ($checkData[$keyName] ?? []))) {
                    if ($resideCount == 0) {
                        $tmp = $checkData[$keyName][$foundElementName];
                        $elementData[$keyName][$foundElementName] = [$tmp];
                    }
                    $elementData[$keyName][$foundElementName][] = $extract[$foundElementName];
                    $resideCount++;
                } else {
                    $elementData[$keyName][$foundElementName] = $extract[$foundElementName];
                }

            }
        }

        return $elementData;
    }

    private function isWhitespace(): bool {
        return $this->reader->nodeType == XMLReader::SIGNIFICANT_WHITESPACE;
    }

    private function isValue(): bool {
        return $this->reader->nodeType == XMLReader::TEXT || $this->reader->nodeType === XMLReader::CDATA;
    }

    private function isEndElement(): bool {
        return $this->reader->nodeType == XMLReader::END_ELEMENT;
    }

    private function equaledDepth($parentDepth): bool {
        return $this->reader->depth === $parentDepth;
    }

    private function getCurrentElementAttributes(): array {
        $attributes = [];
        if ($this->reader->hasAttributes) {
            while ($this->reader->moveToNextAttribute()) {
                $attributes[$this->reader->name] = $this->insureValue($this->reader->value);
            }
        }
        $this->reader->moveToElement();
        return ['attributes' => $attributes];
    }

    private function isElement(): bool {
        return $this->reader->nodeType == XMLReader::ELEMENT;
    }

    private function shouldBeSkipped(): bool {
        return $this->shouldBeSkippedElement() || $this->shouldBeSkippedDepth();
    }

    private function shouldBeSkippedElement(): bool {
        if ($this->skipElements >= 1) {
            $this->skipElements--;
            return true;
        }

        return false;
    }

    private function shouldBeSkippedDepth(): bool {
        if ($this->reader->depth < $this->skipDepth) {
            return true;
        }

        return false;
    }

    /**
     * @throws IncompleteParseException
     */
    private function close() {
        if (!$this->reader->close()) {
            throw new IncompleteParseException();
        }
    }

}

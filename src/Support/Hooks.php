<?php

namespace NoriaLabs\Payments\Support;

class Hooks
{
    /**
     * @param  callable|array<int, callable>|null  $beforeRequest
     * @param  callable|array<int, callable>|null  $afterResponse
     * @param  callable|array<int, callable>|null  $onError
     */
    public function __construct(
        public readonly mixed $beforeRequest = null,
        public readonly mixed $afterResponse = null,
        public readonly mixed $onError = null,
    ) {}

    /**
     * @return array<int, callable>
     */
    public function beforeRequestCallbacks(): array
    {
        return $this->normalize($this->beforeRequest);
    }

    /**
     * @return array<int, callable>
     */
    public function afterResponseCallbacks(): array
    {
        return $this->normalize($this->afterResponse);
    }

    /**
     * @return array<int, callable>
     */
    public function onErrorCallbacks(): array
    {
        return $this->normalize($this->onError);
    }

    /**
     * @return array<int, callable>
     */
    private function normalize(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_callable($value)) {
            return [$value];
        }

        $hooks = [];

        foreach (is_array($value) ? $value : [] as $hook) {
            if (is_callable($hook)) {
                $hooks[] = $hook;
            }
        }

        return $hooks;
    }
}

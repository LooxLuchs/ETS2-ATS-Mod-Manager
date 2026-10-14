<?php

declare(strict_types=1);

namespace Ets2AtsModManager\Http\Controllers;

use Ets2AtsModManager\Models\Activation;
use Ets2AtsModManager\Services\PathTemplate;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Pterodactyl\Facades\Activity;
use Pterodactyl\Models\Egg;

final class AdminController
{
    public function index(): array
    {
        return ['activations' => Activation::orderBy('id')->get(),
            'eggs' => Egg::orderBy('name')->get(['id', 'name']),
            'placeholders' => array_map(fn ($p) => '{{env.'.$p.'}}', PathTemplate::PLACEHOLDERS)];
    }

    public function store(Request $request): array
    {
        return $this->save($request, new Activation);
    }

    public function update(Request $request, string $activation): array
    {
        return $this->save($request, Activation::findOrFail($activation));
    }

    public function destroy(string $activation): array
    {
        Activation::findOrFail($activation)->delete();
        Activity::event('ets2-ats-mod-manager:activation.delete')->property('activation', $activation)->log();

        return ['ok' => true];
    }

    private function save(Request $request, Activation $model): array
    {
        $data = $request->validate([
            'egg_id' => ['required', 'integer', 'exists:eggs,id', Rule::unique($model->getTable(), 'egg_id')->ignore($model->id)],
            'game' => ['required', Rule::in(['ets2', 'ats'])],
            'path_template' => ['required', 'string', 'max:2048'], 'enabled' => ['required', 'boolean'],
        ]);
        try {
            PathTemplate::validate($data['path_template']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['path_template' => $e->getMessage()]);
        }
        $model->fill($data)->save();
        Activity::event('ets2-ats-mod-manager:activation.save')->property('activation', $model->id)->log();

        return ['activation' => $model];
    }
}

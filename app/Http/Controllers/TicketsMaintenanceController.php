<?php

namespace App\Http\Controllers;

use App\Support\AppState;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mantenimiento del sistema: estado global (modo test) y blanqueo de la base.
 *
 * Son acciones de alto impacto, asi que quedan reservadas al administrador
 * pleno (no al administrativo, que ni siquiera ve la pantalla Configuracion).
 */
class TicketsMaintenanceController extends Controller
{
    public function state(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        return response()->json([
            'test_mode' => AppState::isTestMode(),
        ]);
    }

    /**
     * Activa o desactiva el modo test. Al desactivar se borran los datos
     * cargados mientras estuvo activo.
     */
    public function setTestMode(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        AppState::setTestMode((bool) $data['enabled']);

        return response()->json([
            'test_mode' => AppState::isTestMode(),
            'message' => $data['enabled']
                ? 'Modo test activado.'
                : 'Modo test desactivado. Se borraron los datos de prueba.',
        ]);
    }

    /**
     * Blanqueo total: deja la base como recien instalada y solo al admin.
     */
    public function resetDatabase(Request $request): JsonResponse
    {
        $this->assertAdmin($request);

        AppState::resetDatabase();

        return response()->json([
            'message' => 'Base de datos blanqueada. Volve a iniciar sesion.',
        ]);
    }

    private function assertAdmin(Request $request): void
    {
        $user = $request->user();

        if (! $user || ! $user->isStaffAdmin()) {
            abort(403, 'Solo el administrador puede hacer esto.');
        }
    }
}

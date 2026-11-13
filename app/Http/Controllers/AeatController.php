<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Services\Auditoria;
use App\Services\VerificacionNif;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/** Certificado del despacho para consultar el censo de la AEAT (verificación de NIF). Solo administración. */
class AeatController extends Controller
{
    public function __construct(private VerificacionNif $srv) {}

    private function volver(): RedirectResponse
    {
        return redirect()->route('parametros', 'clientes');
    }

    public function guardar(Request $request): RedirectResponse
    {
        $request->validate(['certificado' => ['required', 'file', 'max:64', 'extensions:p12,pfx'], 'pass' => ['required', 'string', 'max:200']],
            ['certificado.required' => 'Elige el fichero del certificado (.p12 o .pfx).', 'certificado.extensions' => 'El certificado tiene que ser un fichero .p12 o .pfx.', 'pass.required' => 'Escribe la contraseña del certificado.']);
        try {
            $d = $this->srv->guardar($request->file('certificado'), (string) $request->input('pass'));
        } catch (InvalidArgumentException $e) {
            return $this->volver()->withErrors(['aeat' => $e->getMessage()]);
        }
        Auditoria::registrar('aeat.certificado', 'Certificado para consultas a la AEAT guardado: '.$d['titular'].' (caduca '.$d['caduca'].')');

        return $this->volver()->with('ok', 'Certificado de '.$d['titular'].' guardado (cifrado). Desde ahora se verifica en la AEAT el NIF de los clientes al guardarlos.');
    }

    public function activar(Request $request): RedirectResponse
    {
        $this->srv->activar($request->boolean('activo'));
        Auditoria::registrar('aeat.certificado', 'Verificación del NIF en la AEAT '.($request->boolean('activo') ? 'activada' : 'desactivada'));

        return $this->volver()->with('ok', 'Verificación del NIF en la AEAT '.($request->boolean('activo') ? 'activada.' : 'desactivada.'));
    }

    public function quitar(): RedirectResponse
    {
        $this->srv->quitar();
        Auditoria::registrar('aeat.certificado', 'Certificado para consultas a la AEAT eliminado');

        return $this->volver()->with('ok', 'Certificado eliminado. Ya no se consulta la AEAT.');
    }

    /** Prueba la conexión con el NIF del despacho (o un NIF y nombre que se indiquen) */
    public function probar(Request $request): RedirectResponse
    {
        $desp = Cliente::despacho();
        $nif = strtoupper(trim((string) $request->input('nif'))) ?: (string) ($desp?->nif ?: ($this->srv->certificado()['nif'] ?? ''));
        $nombre = trim((string) $request->input('nombre')) ?: (string) ($desp?->nif ? $desp->nombre : ($this->srv->certificado()['titular'] ?? ''));
        if ($nif === '') {
            return $this->volver()->withErrors(['aeat' => 'Indica un NIF y un nombre para probar.']);
        }
        $r = $this->srv->comprobar($nif, $nombre);
        Auditoria::registrar('aeat.prueba', 'Prueba de verificación de NIF en la AEAT: '.$nif.' → '.($r['resultado'] ?: $r['estado']));

        return $this->volver()->with($r['estado'] === 'error' ? 'aviso' : 'ok', 'Prueba con '.$nif.' («'.$nombre.'»): '.$r['msg']);
    }
}

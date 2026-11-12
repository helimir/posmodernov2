/**
 * Las PETICIONES JSON de la aplicacion: el token CSRF, el `Accept` y el
 * `errorMsg` del servidor.
 *
 * REGLA que fija este fichero: *toda escritura sale por aqui*. Una peticion
 * sin el token CSRF recibe un 419 y no se guarda nada; y un fallo del
 * servidor se enseña con SU mensaje, el `errorMsg`, porque el servidor sabe
 * mas del motivo del fallo que el navegador.
 *
 * El `errorMsg` es el CONTRATO de error de la API: un 422 con
 * `{"errorMsg": "..."}`. La pantalla lo enseña tal cual.
 */

/**
 * El error de la API: el `errorMsg` del servidor, si lo hay.
 */
export interface ApiError {
    errorMsg?: string;
}

/**
 * El token CSRF de la pagina.
 *
 * Vive en el `meta` de la pagina: cada pagina trae el suyo, y el del login
 * deja de valer en cuanto se entra (entrar regenera la sesion).
 */
export function csrfToken(): string {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * Una peticion JSON, con el token CSRF y con la respuesta en JSON.
 *
 * Un fallo LANZA con el mensaje del servidor: la pantalla lo enseña en vez
 * de seguir como si nada.
 *
 * @param  url  La URL de la peticion.
 * @param  method  El verbo: `GET`, `POST` o `PUT`.
 * @param  payload  El cuerpo, si lo hay.
 */
async function request<T>(url: string, method: string, payload?: unknown): Promise<T> {
    const headers: Record<string, string> = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken(),
    };

    const init: RequestInit = {
        method,
        headers,
        credentials: 'same-origin',
    };

    if (payload !== undefined) {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(payload);
    }

    const response = await fetch(url, init);

    const data = (await response.json().catch(() => ({}))) as ApiError;

    if (!response.ok) {
        throw new Error(data.errorMsg ?? `The request failed with the status ${response.status}.`);
    }

    return data as T;
}

/**
 * Una LECTURA: un GET que responde JSON.
 */
export function getJson<T>(url: string): Promise<T> {
    return request<T>(url, 'GET');
}

/**
 * Una ESCRITURA: un POST que responde JSON.
 */
export function postJson<T>(url: string, payload: unknown): Promise<T> {
    return request<T>(url, 'POST', payload);
}

/**
 * Una ESCRITURA: un PUT que responde JSON.
 */
export function putJson<T>(url: string, payload: unknown): Promise<T> {
    return request<T>(url, 'PUT', payload);
}

/**
 * Una ESCRITURA: un DELETE que responde JSON.
 *
 * El cuerpo va vacio: un borrado no lleva datos, solo la cuenta que se
 * borra en la propia URL.
 */
export function deleteJson<T>(url: string): Promise<T> {
    return request<T>(url, 'DELETE');
}

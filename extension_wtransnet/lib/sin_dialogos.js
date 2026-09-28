// Se inyecta en las páginas de Wtransnet SÓLO mientras la extensión está trabajando
// (se registra al empezar y se retira al terminar). Evita que las ventanas emergentes
// del navegador («Aceptar», «¿Seguro que quiere salir?») bloqueen la búsqueda.
//  - alert(): se anota el mensaje y se continúa.
//  - confirm(): se CANCELA siempre, salvo justo después de pulsar «Buscar» y sólo si el
//    mensaje no habla de ofertar, aceptar, contratar, enviar, borrar, pagar…
//  - prompt(): se cancela.
//  - «¿Salir del sitio?» (beforeunload): se suprime.
(() => {
  if (window.__wtSinDialogos) return;
  window.__wtSinDialogos = true;
  const PROHIBIDO = /ofert|acept|contrat|envi|borr|elimin|archiv|inter[eé]s|public|reserv|pag|compr|guard|grab|cancel/i;
  const raiz = () => document.documentElement;
  const anotar = (tipo, msg) => {
    try {
      const prev = JSON.parse(raiz().getAttribute("data-wt-dialogos") || "[]");
      prev.push({ tipo, msg: String(msg == null ? "" : msg).slice(0, 300) });
      raiz().setAttribute("data-wt-dialogos", JSON.stringify(prev.slice(-20)));
    } catch (e) { /* sin documento todavía */ }
  };
  window.alert = (m) => anotar("aviso", m);
  window.confirm = (m) => {
    const permitido = raiz() && raiz().getAttribute("data-wt-permitir-confirm") === "1" && !PROHIBIDO.test(String(m));
    anotar(permitido ? "confirmación aceptada (tras Buscar)" : "confirmación cancelada", m);
    return permitido;
  };
  window.prompt = (m) => { anotar("pregunta cancelada", m); return null; };
  window.addEventListener("beforeunload", (e) => { e.stopImmediatePropagation(); }, true);
  try {
    Object.defineProperty(window, "onbeforeunload", { get() { return null; }, set() {}, configurable: true });
  } catch (e) { window.onbeforeunload = null; }
})();

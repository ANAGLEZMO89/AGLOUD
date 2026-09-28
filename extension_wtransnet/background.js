// Al pulsar el icono se abre (o se enfoca) el panel del buscador en una pestaña.
chrome.action.onClicked.addListener(async () => {
  const url = chrome.runtime.getURL("panel.html");
  const [existente] = await chrome.tabs.query({ url });
  if (existente) {
    await chrome.tabs.update(existente.id, { active: true });
    await chrome.windows.update(existente.windowId, { focused: true });
  } else {
    await chrome.tabs.create({ url });
  }
});

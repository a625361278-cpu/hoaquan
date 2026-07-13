const I18N_MARKER = '[[I18N]]';

const LEGACY_LOG_RULES = [
  [/^\[WARN\] 运行连接断开，等待自动重连$/, 'client.logs.system.runtime_connection_closed_reconnecting'],
  [/^\[WARN\] 运行连接丢失，等待自动重连$/, 'client.logs.system.runtime_connection_missing_reconnecting'],
  [/^\[WARN\] 运行异常停止，等待自动重连$/, 'client.logs.system.runtime_stopped_reconnecting'],
  [/^\[ERROR\] 运行连接断开$/, 'client.logs.system.runtime_connection_closed'],
  [/^\[WARN\] 启动确认已失效，已忽略过期回包$/, 'client.logs.system.start_confirmation_expired'],
  [/^\[INFO\] 启动游戏成功$/, 'client.logs.system.game_started'],
  [/^\[INFO\] 已重新下发启动指令，等待服务器确认$/, 'client.logs.system.restart_command_sent'],
  [/^\[WARN\] 配额到期，已发送停止指令$/, 'client.logs.system.quota_expired_stop_sent'],
  [/^\[ERROR\] 启动成功后角色绑定失败：(.*)$/, 'client.logs.system.role_bind_failed', 'error'],
  [/^\[ERROR\] 自动重连失败次数过多，已停止重试：(.*)$/, 'client.logs.system.auto_reconnect_stopped', 'error'],
  [/^\[WARN\] 自动重连失败，将稍后重试：(.*)$/, 'client.logs.system.auto_reconnect_retry_later', 'error'],
];

function translatePayload(prefix, payload, t, original) {
  if (!payload || typeof payload.key !== 'string' || !payload.key.startsWith('client.logs.system.')) {
    return original;
  }
  const params = payload.params && typeof payload.params === 'object' && !Array.isArray(payload.params)
    ? payload.params
    : {};
  const translated = t(payload.key, params);
  if (!translated || translated === payload.key) {
    return original;
  }
  return prefix ? `${prefix} ${translated}` : translated;
}

export function translateGameLogLine(value, t) {
  const line = String(value ?? '');
  const markerIndex = line.indexOf(I18N_MARKER);
  if (markerIndex >= 0) {
    const prefix = line.slice(0, markerIndex).trimEnd();
    try {
      return translatePayload(prefix, JSON.parse(line.slice(markerIndex + I18N_MARKER.length)), t, line);
    } catch {
      return line;
    }
  }

  for (const [pattern, key, paramName] of LEGACY_LOG_RULES) {
    const match = line.match(pattern);
    if (!match) continue;
    const levelMatch = line.match(/^\[[^\]]+\]/);
    const params = paramName ? { [paramName]: match[1] || '' } : {};
    return translatePayload(levelMatch?.[0] || '', { key, params }, t, line);
  }
  return line;
}

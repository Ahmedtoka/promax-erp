# ZKTeco fallback agent

The normal path is **ADMS push**: the K40 Pro sends every punch to `https://<server>/iclock/cdata` by itself, and nothing runs in the office. Setup steps are on the **HR → Fingerprint devices** screen.

Use this agent only if ADMS cannot work. That happens when the firmware has no *Cloud Server Setting* menu, or the device can't reach the server.

1. On one office PC on the same network as the device, install the packages:
   ```
   pip install pyzk requests
   ```
2. Register the device serial in PROMAX (*Fingerprint devices*).
3. Run the agent:
   ```
   python zk_agent.py --device 192.168.1.201 --server https://<promax-domain> --serial <SN> --loop 60
   ```
   Use `--comm-key` if a Comm Key is set on the device, and `--udp` for old firmware.
4. To keep it running, add a Windows Task Scheduler job "At startup" that runs the command above.

The agent posts in the same ATTLOG format as the device. The server dedupes on (device, PIN, time), so re-sending is harmless.

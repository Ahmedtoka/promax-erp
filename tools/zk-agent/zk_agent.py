#!/usr/bin/env python3
"""
PROMAX — ZKTeco fallback agent (2026-11-07).

Use this ONLY if the device cannot push by itself (no "Cloud Server Setting / ADMS"
menu, or the firmware refuses to connect). It runs on any office PC on the same
network as the device, pulls punches over the ZK protocol (TCP/UDP 4370) and posts
them to PROMAX in the exact ADMS ATTLOG format — so the server treats it like the
device itself (same serial, same dedupe, same attendance rules).

    pip install pyzk requests
    python zk_agent.py --device 192.168.1.201 --server https://erp.example.com --serial CKJK211160123

Run it every minute (Windows Task Scheduler) or with --loop 60.
State (last pushed punch time) is kept in zk_agent_state.json next to this file.
Re-sending is harmless: the server ignores punches it already has.
"""

import argparse
import json
import os
import sys
import time

try:
    import requests
    from zk import ZK
except ImportError:
    sys.exit("Missing packages: pip install pyzk requests")

STATE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "zk_agent_state.json")


def load_state():
    try:
        with open(STATE, encoding="utf-8") as f:
            return json.load(f)
    except (OSError, ValueError):
        return {}


def save_state(state):
    with open(STATE, "w", encoding="utf-8") as f:
        json.dump(state, f)


def run_once(args):
    state = load_state()
    since = state.get("last", "")

    zk = ZK(args.device, port=args.port, timeout=10, password=args.comm_key, force_udp=args.udp, ommit_ping=True)
    conn = zk.connect()
    try:
        conn.disable_device()
        records = conn.get_attendance()
    finally:
        try:
            conn.enable_device()
        finally:
            conn.disconnect()

    lines, newest = [], since
    for r in records:
        ts = r.timestamp.strftime("%Y-%m-%d %H:%M:%S")
        if ts <= since:
            continue
        # PIN \t TIME \t STATUS \t VERIFY \t WORKCODE  — same as the device's own ADMS push
        lines.append("\t".join([str(r.user_id), ts, str(r.punch or 0), str(r.status or 1), "0"]))
        newest = max(newest, ts)

    if not lines:
        print("no new punches")
        return

    url = args.server.rstrip("/") + "/iclock/cdata"
    for i in range(0, len(lines), 500):
        chunk = "\n".join(lines[i:i + 500]) + "\n"
        res = requests.post(url, params={"SN": args.serial, "table": "ATTLOG", "Stamp": "agent"},
                            data=chunk.encode("utf-8"), headers={"Content-Type": "text/plain"}, timeout=30)
        if res.status_code != 200 or not res.text.startswith("OK"):
            sys.exit(f"server refused: {res.status_code} {res.text[:200]}")
        print(res.text.strip())

    state["last"] = newest
    save_state(state)


def main():
    p = argparse.ArgumentParser(description="Pull ZKTeco punches over 4370 and push them to PROMAX")
    p.add_argument("--device", required=True, help="device IP on the office network")
    p.add_argument("--port", type=int, default=4370)
    p.add_argument("--comm-key", type=int, default=0, help="Comm Key set on the device (0 = none)")
    p.add_argument("--udp", action="store_true", help="force UDP (older firmware)")
    p.add_argument("--server", required=True, help="PROMAX base URL")
    p.add_argument("--serial", required=True, help="device serial registered in PROMAX (Fingerprint devices)")
    p.add_argument("--loop", type=int, default=0, help="repeat every N seconds (0 = run once)")
    args = p.parse_args()

    while True:
        try:
            run_once(args)
        except Exception as exc:  # keep looping through network blips
            print(f"error: {exc}", file=sys.stderr)
            if not args.loop:
                sys.exit(1)
        if not args.loop:
            break
        time.sleep(args.loop)


if __name__ == "__main__":
    main()

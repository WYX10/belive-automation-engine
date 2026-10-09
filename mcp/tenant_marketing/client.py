"""PHP bridge: JSON in/out, official SDK handles the MCP transport."""

import asyncio
import json
from pathlib import Path
import sys

from mcp import Client
from mcp.client.stdio import StdioServerParameters


async def main() -> None:
    payload = json.load(sys.stdin)
    server = StdioServerParameters(command=sys.executable, args=[str(Path(__file__).with_name("server.py"))])
    async with Client(server, raise_exceptions=True, read_timeout_seconds=5) as client:
        result = await client.call_tool("tenant_marketing_plan", payload)
        print(json.dumps(result.structured_content, ensure_ascii=False))


if __name__ == "__main__":
    asyncio.run(asyncio.wait_for(main(), timeout=8))

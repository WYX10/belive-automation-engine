import unittest
from pathlib import Path
import sys

from mcp import Client
from mcp.client.stdio import StdioServerParameters


class MarketingProtocolTest(unittest.IsolatedAsyncioTestCase):
    async def test_stdio_discovery_and_tool_execution(self):
        server = StdioServerParameters(command=sys.executable, args=[str(Path(__file__).with_name("server.py"))])
        async with Client(server, raise_exceptions=True, read_timeout_seconds=5) as client:
            tools = await client.list_tools()
            self.assertEqual({t.name for t in tools.tools}, {"qualify_tenant", "match_room_benefits", "handle_rental_objection", "tenant_marketing_plan"})
            prompts = await client.list_prompts()
            self.assertIn("tenant_conversation_guidance", {p.name for p in prompts.prompts})
            result = await client.call_tool("tenant_marketing_plan", {"requirements": {"location": "Cheras", "budget": 650}, "rooms": [], "intent": "price_enquiry"})
            self.assertEqual(result.structured_content["qualification"]["ask_next"], "move_in_date")
            self.assertFalse(result.structured_content["room_benefits"]["has_inventory"])


if __name__ == "__main__":
    unittest.main()

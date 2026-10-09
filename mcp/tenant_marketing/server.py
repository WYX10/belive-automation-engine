"""Local stdio MCP server: no network listener, credentials, or database access."""

from mcp.server import MCPServer
from mcp.types import ToolAnnotations

import skills

mcp = MCPServer("BeLive tenant marketing")
annotations = ToolAnnotations(readOnlyHint=True, destructiveHint=False, openWorldHint=False)
for skill in (skills.qualify_tenant, skills.match_room_benefits, skills.handle_rental_objection, skills.tenant_marketing_plan):
    mcp.tool(annotations=annotations)(skill)


@mcp.prompt()
def tenant_conversation_guidance() -> str:
    """Explain how to use the marketing tools with verified tenant context."""
    return ("Load only the current tenant's saved requirements and verified available room inventory. "
            "Call tenant_marketing_plan, answer their question, explain relevant verified benefits, "
            "and offer one optional next step. Tenant preferences never become shared facts.")


if __name__ == "__main__":
    mcp.run(transport="stdio")

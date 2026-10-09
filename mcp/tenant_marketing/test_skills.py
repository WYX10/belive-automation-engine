import unittest

from skills import handle_rental_objection, match_room_benefits, tenant_marketing_plan


class MarketingSkillsTest(unittest.TestCase):
    def test_match_ranks_fit_and_discloses_tradeoffs(self):
        requirements = {"location": "Cheras", "budget": 700, "tenure": "monthly", "amenities": ["Wi-Fi", "aircon"], "occupants": 2}
        rooms = [
            {"id": 1, "location": "Sentul", "room_type": "single", "prices": {"monthly": 800}, "amenities": ["WiFi"]},
            {"id": 2, "location": "Cheras", "room_type": "single", "prices": {"monthly": 650}, "amenities": ["wifi", "air conditioning"]},
        ]
        result = match_room_benefits(requirements, rooms)["rooms"]
        self.assertEqual(result[0]["room_id"], 2)
        self.assertEqual(result[0]["monthly_price_rm"], 650)
        self.assertEqual(result[0]["matched_amenities"], ["aircon", "wifi"])
        self.assertIn("occupant capacity unconfirmed", result[0]["unconfirmed_requirements"])
        self.assertIn("location differs", result[1]["unconfirmed_requirements"])
        self.assertIn("over budget", result[1]["unconfirmed_requirements"])

    def test_unknown_tenure_price_is_not_invented(self):
        room = {"id": 1, "location": "Cheras", "prices": {"12_month": 600}}
        result = match_room_benefits({"tenure": "monthly"}, [room])["rooms"][0]
        self.assertIsNone(result["monthly_price_rm"])
        self.assertIn("monthly price unknown", result["unconfirmed_requirements"])

    def test_budget_objection_never_changes_requested_commitment(self):
        plan = tenant_marketing_plan({"budget": 500, "tenure": "monthly"}, [], "objection", "budget")
        self.assertIn("without assuming a longer stay", plan["objection"]["guidance"])
        self.assertEqual(plan["qualification"]["ask_next"], "location")
        self.assertFalse(plan["room_benefits"]["has_inventory"])

    def test_trust_objection_requires_evidence(self):
        self.assertIn("Do not claim certification", handle_rental_objection("trust")["guidance"])


if __name__ == "__main__":
    unittest.main()

import os
import subprocess
import sys
from pathlib import Path

from django.core.exceptions import ImproperlyConfigured
from django.test import SimpleTestCase

from config.guards import check_auth_mode

SERVICE_DIR = Path(__file__).resolve().parents[2]


class AuthModeGuardTests(SimpleTestCase):
    def test_jwt_is_always_allowed(self):
        for debug in (True, False):
            check_auth_mode("jwt", debug=debug, testing=False)

    def test_dev_allowed_in_debug_and_in_tests(self):
        check_auth_mode("dev", debug=True, testing=False)
        check_auth_mode("dev", debug=False, testing=True)

    def test_dev_refused_when_debug_is_false(self):
        with self.assertRaisesMessage(ImproperlyConfigured, "DJANGO_DEBUG=false"):
            check_auth_mode("dev", debug=False, testing=False)

    def test_unknown_mode_refused(self):
        with self.assertRaises(ImproperlyConfigured):
            check_auth_mode("none", debug=True, testing=False)

    def test_service_refuses_to_start_with_dev_auth_and_debug_false(self):
        """Real startup (manage.py check) in a separate process, with the forbidden combination."""
        env = {**os.environ, "AUTH_MODE": "dev", "DJANGO_DEBUG": "false", "EUREKA_ENABLED": "false"}
        result = subprocess.run([sys.executable, "manage.py", "check"], cwd=SERVICE_DIR, env=env,
                                capture_output=True, text=True, timeout=60)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn("AUTH_MODE=dev est interdit", result.stderr)

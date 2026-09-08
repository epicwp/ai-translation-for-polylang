import { render } from "@wordpress/element";
import TranslationDashboard from "./components/TranslationDashboard";

const root = document.getElementById("pllat_translation_dashboard");

if (root) {
	render(<TranslationDashboard />, root);
}

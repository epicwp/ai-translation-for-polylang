import { render } from "@wordpress/element";
import TranslationDashboard from "./components/TranslationDashboard";
import ProCard from "./components/ProCard";

const root = document.getElementById("pllat_translation_dashboard");

if (root) {
	render(<TranslationDashboard CardActions={ProCard} />, root);
}

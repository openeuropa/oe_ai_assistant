import type { Meta, StoryObj } from "@storybook/react-vite";
import { TemplatePanel } from "../../../src/plugins/drafting/components/template-panel";
import { useDraftingTemplate } from "../../../src/plugins/drafting/hooks/use-drafting-template";

const meta = {
  title: "Drafting/Template panel",
  component: TemplatePanel,
  parameters: {
    layout: "padded",
  },
} satisfies Meta<typeof TemplatePanel>;

export default meta;
type Story = StoryObj<typeof meta>;

/** Interactive wrapper backed by the mock template hook. */
function InteractiveTemplate() {
  const template = useDraftingTemplate();
  return (
    <div className="max-w-2xl border border-gray-200 bg-white">
      <TemplatePanel
        options={template.options}
        value={template.value}
        onChange={template.updateValue}
        onSave={template.submitValues}
        onCancel={template.discardChanges}
        hasChanges={template.hasChanges}
      />
    </div>
  );
}

export const Default: Story = {
  render: () => <InteractiveTemplate />,
};

export const Saving: Story = {
  render: () => {
    const template = useDraftingTemplate();
    return (
      <div className="max-w-2xl border border-gray-200 bg-white">
        <TemplatePanel
          options={template.options}
          value={template.options[1]?.value ?? template.value}
          onChange={() => {}}
          onSave={async () => {}}
          onCancel={() => {}}
          hasChanges
          isSaving
        />
      </div>
    );
  },
};

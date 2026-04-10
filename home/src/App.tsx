import React from 'react';
import { Navbar } from './components/Navbar';
import { HeroSection } from './components/HeroSection';
import { SocialProof } from './components/SocialProof';
import { ProblemSolution } from './components/ProblemSolution';
import { CoreFeatures } from './components/CoreFeatures';
import { AdvancedFeatures } from './components/AdvancedFeatures';
import { PremiumFeatures } from './components/PremiumFeatures';
import { HowItWorks } from './components/HowItWorks';
import { PricingSection } from './components/PricingSection';
import { FAQSection } from './components/FAQSection';
import { FinalCTA } from './components/FinalCTA';
import { Footer } from './components/Footer';
export function App() {
  return (
    <div className="min-h-screen flex flex-col font-sans selection:bg-terracotta/20 selection:text-forest bg-cream">
      <Navbar />
      <main className="flex-grow">
        <HeroSection />
        <SocialProof />
        <ProblemSolution />
        <CoreFeatures />
        <AdvancedFeatures />
        <PremiumFeatures />
        <HowItWorks />
        <PricingSection />
        <FAQSection />
        <FinalCTA />
      </main>
      <Footer />
    </div>);

}